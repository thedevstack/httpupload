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
prosody.unlock_globals();
--require"https";
local st = require"util.stanza";
local http = (string.len(external_url) >= 5 and string.sub(external_url,1,5) == "https") and require"ssl.https" or require"socket.http";
local json = require"util.json";
local dataform = require "util.dataforms".new;
local ltn12 = require"ltn12";
local rsm = require"util.rsm";
prosody.lock_globals();

-- depends
module:depends("disco");

-- namespace
local xmlns_filetransfer_http = "urn:xmpp:filetransfer:http";
local xmlns_http_upload = "urn:xmpp:http:upload";
local xmlns_http_upload_0 = "urn:xmpp:http:upload:0";

-- versions
spec_version = "v0.4";
impl_version = "v0.4-dev";

module:add_feature(xmlns_filetransfer_http);
module:add_feature(xmlns_http_upload);
module:add_feature(xmlns_http_upload_0);

if filetransfer_manager_ui_url then
   -- add additional disco info to advertise managing UI
   module:add_extension(dataform {
        { name = "FORM_TYPE", type = "hidden", value = xmlns_filetransfer_http },
        { name = "filetransfer-manager-ui-url", type = "text-single" },
   }:form({ ["filetransfer-manager-ui-url"] = filetransfer_manager_ui_url }, "result"));
end

local function buildRequestBody(reqparams)
  module:log("debug", "param count " .. #reqparams);
  local params = {};
  for k,v in pairs(reqparams) do
    if v ~= nil then
      params[#params + 1] = k .. "=" .. tostring(v);
    end
  end
  return table.concat(params, "&");
end

local function listfiles(origin, orig_from, stanza, request)
   local rsmSet = rsm.get(request);
   local limit = rsmSet and rsmSet.max or -1;
   local descending = rsmSet and rsmSet.before or nil;
   local index = rsmSet and rsmSet.index or 0;
   --local before, after = rsmSet and rsmSet.before, rsmSet and rsmSet.after;
   --if type(before) ~= "string" then before = nil; end
   local filter = request.attr.filter or nil;
   local from = request.attr.from or nil;
   local to = request.attr.to or nil;
   local with = request.attr.with or nil;
   -- build the body
   local reqparams = {
      ["xmpp_server_key"] = xmpp_server_key,
      ["slot_type"] = "list",
      ["user_jid"] = orig_from,
      ["offset"] = index,
      ["limit"] = limit,
      ["descending"] = descending,
      ["filter"] = filter,
      ["from"] = from,
      ["to"] = to,
      ["with"] = with
    };

   --local reqbody = "xmpp_server_key=" .. xmpp_server_key .. "&slot_type=list&user_jid=" .. orig_from ..
   -- "&offset=" .. index .. "&limit=" .. limit .. "&descending=" .. tostring(descending);
   --reqbody = reqbody .. "&filter=" .. filter .. "&from=" .. from .. "&to=" .. to .. "&with=" .. with;
   local reqbody = buildRequestBody(reqparams);
   module:log("debug", "Request body: " .. reqbody);
   -- the request
   local respbody, statuscode = http.request(external_url, reqbody);
   -- respbody is nil in case the server is not reachable
   if respbody ~= nil then
     respbody = string.gsub(respbody, "\\/", "/");
   end
  
   local list;
   local count = 0;
   local first = {};
   first.index = index;
  
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
      if respobj.list then
        list = respobj.list.files;
        count = respobj.list.count;
      end
    end
  else
    origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
    return true;
  end
  
  local addedfiles = 0;
  local reply = st.reply(stanza);
  reply:tag("list", {xmlns=xmlns_filetransfer_http});
  if count > 0 then
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
  if count == 0 or addedfiles == 0 then
    reply:tag("empty"):up();
  end
  reply:add_child(rsm.generate{first = first, count = count})
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
                    :tag("missing-parameter", {xmlns=xmlns_filetransfer_http})
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
     reply:tag("deleted", { xmlns = xmlns_filetransfer_http });
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

local function version(origin, stanza)
  local reply = st.reply(stanza);
  reply:tag("version", { xmlns = xmlns_filetransfer_http });
  reply:tag("xmpp-fileservice-module", { spec = spec_version, implementation = impl_version }):up();
  -- the request
  local respbody, statuscode = http.request(external_url .. "?action=version");
  -- respbody is nil in case the server is not reachable
  if respbody ~= nil then
     respbody = string.gsub(respbody, "\\/", "/");
  end

  local http_spec_version = nil;
  local http_impl_version = nil;

  -- check the response
  if statuscode == 200 then
    local respobj, pos, err = json.decode(respbody);
    if err then
        -- do nothing for the moment
    else
      if respobj["spec"] ~= nil and respobj["impl"] ~= nil then
        http_spec_version = respobj.spec;
        http_impl_version = respobj.impl;
        reply:tag("http-fileservice-module", { spec = http_spec_version, implementation = http_impl_version }):up();
      end
    end
  end

  origin.send(reply);
  return true;
end

local function create_upload_slot_with_childs(origin, orig_from, stanza, request, namespace, recipient)
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

   local content_type = request:get_child_text("content-type");
   return create_upload_slot(origin, orig_from, stanza, namespace, recipient, filename, filesize, content_type);
end

local function create_upload_slot(origin, orig_from, stanza, namespace, recipient, filename, filesize, content_type)
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
                       :tag("file-too-large", {xmlns=namespace})
                       :tag("max-size"):text(tostring(errobj.parameters.max_file_size)));
                 return true;
              elseif errobj.err_code == 3 then
                 origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg)
                       :tag("invalid-character", {xmlns=namespace})
                       :text(errobj.parameters.invalid_character));
                 return true;
              elseif errobj.err_code == 4 then
                 origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg)
                       :tag("missing-parameter", {xmlns=namespace})
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
     reply:tag("slot", { xmlns = namespace });
     if namespace == xmlns_http_upload_0 then
      reply:tag("put", { url = put_url }):up();
      reply:tag("get", { url = get_url }):up();
     else
      reply:tag("get"):text(get_url):up();
      reply:tag("put"):text(put_url):up();
     end

     origin.send(reply);
     return true;
end

-- hooks
module:hook("iq/host/"..xmlns_filetransfer_http..":request", function (event)
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
     local recipient = request.attr.recipient;
     if not recipient then
       origin.send(st.error_reply(stanza, "modify", "bad-request", "Missing or invalid recipient"));
       return true;
     end
     return create_upload_slot(origin, orig_from, stanza, request, xmlns_filetransfer_http, recipient);
   elseif slot_type == "delete" then
     return deletefile(origin, orig_from, stanza, request);
   elseif slot_type == "list" then
     return listfiles(origin, orig_from, stanza, request);
   elseif slot_type == "version" then
     return version(origin, stanza);
   else
    origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
    return true;
   end
end);

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

  return create_upload_slot_with_childs(origin, orig_from, stanza, request, xmlns_http_upload, "Unknown");
end);

module:hook("iq/host/"..xmlns_http_upload_0..":request", function (event)
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

  local filename = request.attr.filename;
    if not filename then
       origin.send(st.error_reply(stanza, "modify", "bad-request", "Invalid filename"));
       return true;
    end
    local filesize = tonumber(request.attr.size);
    if not filesize then
       origin.send(st.error_reply(stanza, "modify", "bad-request", "Missing or invalid file size"));
       return true;
    end

    local content_type = request.attr["content-type"] or "application/octet-stream";

  return create_upload_slot(origin, orig_from, stanza, xmlns_http_upload_0, "Unknown", filename, filesize, content_type);
end);
