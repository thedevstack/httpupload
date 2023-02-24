-- mod_http_upload_external
--
-- Copyright (C) 2016 Sebastian Luksch
--
-- This file is MIT/X11 licensed.
-- 
-- Implementation of HTTP Upload file transfer mechanism used by Conversations
-- 
-- Query external HTTP server to retrieve URLs
--

-- configuration
local external_url = module:get_option("http_upload_external_url");
local xmpp_server_key = module:get_option("http_upload_external_server_key");
local filetransfer_manager_ui_url = module:get_option("filetransfer_manager_ui_url");

-- imports
local st = require"util.stanza";
local http = (string.len(external_url) >= 5 and string.sub(external_url,1,5) == "https") and require"ssl.https" or require"socket.http";
local json = require"util.json";
local dataform = require "util.dataforms".new;
local ltn12 = require"ltn12";

-- depends
module:depends("disco");

-- namespace
local xmlns_http_upload = "urn:xmpp:filetransfer:http";

module:add_feature(xmlns_http_upload);

if filetransfer_manager_ui_url then
   -- add additional disco info to advertise managing UI
   module:add_extension(dataform {
        { name = "FORM_TYPE", type = "hidden", value = xmlns_http_upload },
        { name = "filetransfer-manager-ui-url", type = "text-single" },
   }:form({ ["filetransfer-manager-ui-url"] = filetransfer_manager_ui_url }, "result"));
end

local function listfiles(origin, orig_from, stanza, request)
   -- build the body
   local reqbody = "xmpp_server_key=" .. xmpp_server_key .. "&slot_type=list&user_jid=" .. orig_from;
   -- the request
   local respbody, statuscode = http.request(external_url, reqbody);
   -- respbody is nil in case the server is not reachable
   if respbody ~= nil then
     respbody = string.gsub(respbody, "\\/", "/");
   end
  
   local filelistempty = true;
   local list;
  
   -- check the response
   if statuscode == 500 then
     origin.send(st.error_reply(stanza, "cancel", "service-unavailable", respbody));
     return true;
   elseif statuscode == 406 or statuscode == 400 or statuscode == 403 then
     local errobj, pos, err = json.decode(respbody);
     if err then
        origin.send(st.error_reply(stanza, "wait", "internal-server-error", err));
        return true;
     elseif errobj["err_code"] ~= nil and errobj["msg"] ~= nil then
        if statuscode == 403 then
             origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg));
           return true;
        else
           origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "msg or err_code not found"));
           return true;
        end
     end
  elseif statuscode == 200 then
    local respobj, pos, err = json.decode(respbody);
    if err then
       origin.send(st.error_reply(stanza, "wait", "internal-server-error", err));
       return true;
    else
      -- process json response
      list = respobj.list;
      filelistempty = list == nil or next(list) == nil;
    end
  else
    origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
    return true;
  end
  
  local addedfiles = 0;
  local reply = st.reply(stanza);
  reply:tag("list", {xmlns=xmlns_http_upload});
  if filelistempty == false then
    for i, file in ipairs(list) do
      local url = file.url;
      if url == "" then
        url = nil;
      end
      local fileinfo = file.fileinfo;
      local sender = file.sender_jid;
      if sender == nil then
        sender = "";
      end
      local recipient = file.recipient_jid;
      local sent_time = file.sent_time;
      -- only add file if fileinfo is present
      if fileinfo ~= nil then
        addedfiles = addedfiles + 1;
        reply:tag("file", {timestamp = tostring(sent_time), from = tostring(sender), to = recipient});
        reply:tag("url"):text(url):up();
        reply:tag("file-info");
        reply:tag("filename"):text(fileinfo.filename):up();
        reply:tag("size"):text(fileinfo.filesize):up();
        reply:tag("content-type"):text(fileinfo.content_type):up():up():up();
      end
    end
  end
  if filelistempty or addedfiles == 0 then
    reply:tag("empty"):up();
  end
  origin.send(reply);
  return true;
end

local function deletefile(origin, orig_from, stanza, request)
  -- validate
  local fileurl = request:get_child_text("fileurl");
  if not fileurl or fileurl == '' then
     origin.send(st.error_reply(stanza, "modify", "bad-request", "Invalid fileurl"));
     return true;
  end
  -- build the body
  --local reqbody = "xmpp_server_key=" .. xmpp_server_key .. "&slot_type=delete&file_url=" .. fileurl .. "&user_jid=" .. orig_from;
  -- the request
  local resp = {};
  local client, statuscode = http.request{url=fileurl,
    sink=ltn12.sink.table(resp),
    method="DELETE",
    headers={["X-XMPP-SERVER-KEY"]=xmpp_server_key,
    ["X-USER-JID"]=orig_from}};

  local respbody = table.concat(resp);
  -- respbody is nil in case the server is not reachable
  if respbody ~= nil then
     respbody = string.gsub(respbody, "\\/", "/");
  end

  -- check the response
  if statuscode == 500 then
     origin.send(st.error_reply(stanza, "cancel", "service-unavailable", respbody));
     return true;
  elseif statuscode == 406 or statuscode == 400 or statuscode == 403 then
     local errobj, pos, err = json.decode(respbody);
     if err then
        origin.send(st.error_reply(stanza, "wait", "internal-server-error", err));
        return true;
     else
        if statuscode == 403 and errobj["msg"] ~= nil then
           origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg));
           return true;
        elseif errobj["err_code"] ~= nil and errobj["msg"] ~= nil then
           if errobj.err_code == 4 then
              origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg)
                    :tag("missing-parameter", {xmlns=xmlns_http_upload})
                    :text(errobj.parameters.missing_parameter));
              return true;
           else
              origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "unknown err_code"));
              return true;
           end
        else
           origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "msg or err_code not found"));
           return true;
        end
     end
  elseif statuscode == 204 or statuscode == 404 then
     local reply = st.reply(stanza);
     reply:tag("deleted", { xmlns = xmlns_http_upload });
     origin.send(reply);
     return true;
  elseif respbody ~= nil then
     origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
     return true;
  else
     -- http file service not reachable
     origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode));
     return true;
  end
end

-- hooks
module:hook("iq/host/"..xmlns_http_upload..":request", function (event)
   local stanza, origin = event.stanza, event.origin;
   local orig_from = stanza.attr.from;
   local request = stanza.tags[1];
   -- local clients only
   if origin.type ~= "c2s" then
      origin.send(st.error_reply(stanza, "cancel", "not-authorized"));
      return true;
   end
   -- check configuration
   if not external_url or not xmpp_server_key then
      module:log("debug", "missing configuration options: http_upload_external_url and/or http_upload_external_server_key");
      origin.send(st.error_reply(stanza, "cancel", "internal-server-error"));
      return true;
   end
   local slot_type = request.attr.type;
   if slot_type then
      module:log("debug", "incoming request is of type " .. slot_type);
   else
      module:log("debug", "incoming request has no type - using default type 'upload'");
   end
   
   if not slot_type or slot_type == "upload" then
     -- validate
     local filename = request:get_child_text("filename");
     if not filename then
        origin.send(st.error_reply(stanza, "modify", "bad-request", "Invalid filename"));
        return true;
     end
     local filesize = tonumber(request:get_child_text("size"));
     if not filesize then
        origin.send(st.error_reply(stanza, "modify", "bad-request", "Missing or invalid file size"));
        return true;
     end

     local recipient = request.attr.recipient;
     if not recipient then
       origin.send(st.error_reply(stanza, "modify", "bad-request", "Missing or invalid recipient"));
       return true;
     end

     local content_type = request:get_child_text("content-type");
     
     -- build the body
     local reqbody = "xmpp_server_key=" .. xmpp_server_key .. "&slot_type=upload&size=" .. filesize .. "&filename=" .. filename .. "&user_jid=" .. orig_from .. "&recipient_jid=" .. recipient;
     if content_type then
        reqbody = reqbody .. "&content_type=" .. content_type;
     end

     -- the request
     local respbody, statuscode = http.request(external_url, reqbody);
     -- respbody is nil in case the server is not reachable
     if respbody ~= nil then
        respbody = string.gsub(respbody, "\\/", "/");
     end

     local get_url = nil;
     local put_url = nil;

     -- check the response
     if statuscode == 500 then
        origin.send(st.error_reply(stanza, "cancel", "service-unavailable", respbody));
        return true;
     elseif statuscode == 406 or statuscode == 400 or statuscode == 403 then
        local errobj, pos, err = json.decode(respbody);
        if err then
           origin.send(st.error_reply(stanza, "wait", "internal-server-error", err));
           return true;
        else
           if errobj["err_code"] ~= nil and errobj["msg"] ~= nil then
              if errobj.err_code == 1 then
                 origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg));
                 return true;
              elseif errobj.err_code == 2 then
                 origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg)
                       :tag("file-too-large", {xmlns=xmlns_http_upload})
                       :tag("max-size"):text(errobj.parameters.max_file_size));
                 return true;
              elseif errobj.err_code == 3 then
                 origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg)
                       :tag("invalid-character", {xmlns=xmlns_http_upload})
                       :text(errobj.parameters.invalid_character));
                 return true;
              elseif errobj.err_code == 4 then
                 origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg)
                       :tag("missing-parameter", {xmlns=xmlns_http_upload})
                       :text(errobj.parameters.missing_parameter));
                 return true;
              else
                 origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "unknown err_code"));
                 return true;
              end
           elseif statuscode == 403 and errobj["msg"] ~= nil then
              origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg));
              return true;
           else
              origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "msg or err_code not found"));
              return true;
           end
        end
     elseif statuscode == 200 then
        local respobj, pos, err = json.decode(respbody);
        if err then
           origin.send(st.error_reply(stanza, "wait", "internal-server-error", err));
           return true;
        else
           if respobj["get"] ~= nil and respobj["put"] ~= nil then
              get_url = respobj.get;
              put_url = respobj.put;
           else
              origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "get or put not found"));
              return true;
           end
        end
     elseif respbody ~= nil then
        origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
        return true;
     else
        -- http file service not reachable
        origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode));
        return true;
     end

     local reply = st.reply(stanza);
     reply:tag("slot", { xmlns = xmlns_http_upload });
     reply:tag("get"):text(get_url):up();
     reply:tag("put"):text(put_url):up();
     origin.send(reply);
     return true;
   elseif slot_type == "delete" then
     return deletefile(origin, orig_from, stanza, request);
   elseif slot_type == "list" then
     return listfiles(origin, orig_from, stanza, request);
   else
    origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
    return true;
   end
end);
