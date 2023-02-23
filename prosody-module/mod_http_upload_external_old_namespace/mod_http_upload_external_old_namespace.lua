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

-- imports
require"https";
local st = require"util.stanza";
local http = (string.len(external_url) >= 5 and string.sub(external_url,1,5) == "https") and require"ssl.https" or require"socket.http";
local json = require"util.json";
local ltn12 = require('ltn12');

-- namespace
--local xmlns_http_upload = "urn:xmpp:http:upload";
local xmlns_http_upload = "de:thedevstack:conversationsplus:http:upload";

-- depends
module:depends("disco");
--module:add_identity("store", "file", "HTTP File Upload");
module:add_feature(xmlns_http_upload);

-- hooks
module:hook("iq/host/"..xmlns_http_upload..":request", function (event)
   module:log("info", "HTTPUPLOAD: request");
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
   
   -- build the body
   local reqbody = "xmpp_server_key=" .. xmpp_server_key .. "&size=" .. filesize .. "&filename=" .. filename .. "&user_jid=" .. orig_from;
   if content_type then
      reqbody = reqbody .. "&content_type=" .. content_type;
   end

   -- the request
   --local respbody, statuscode = http.request(external_url, reqbody);
   local resptable = {};
   local res, statuscode = http.request{
      url = external_url,
      protocol = "tlsv1_2",
      method = "POST",
      headers = {
         ["content-type"] = "application/x-www-form-urlencoded",
         ["content-length"] = #reqbody
      },
      source = ltn12.source.string(reqbody),
      sink = ltn12.sink.table(resptable)
   };
   local respbody = string.gsub(table.concat(resptable), "\\/", "/")

   local get_url = nil;
   local put_url = nil;

   -- check the response
   if statuscode == 500 then
      origin.send(st.error_reply(stanza, "cancel", "service-unavailable", respbody));
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
               origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg,
                  st.stanza("file-too-large", {xmlns=xmlns_http_upload})
                     :tag("max-size"):text(errobj.parameters.max_file_size)));
               return true;
            elseif errobj.err_code == 3 then
               origin.send(st.error_reply(stanza, "modify", "not-acceptable", errobj.msg,
                  st.stanza("invalid-character", {xmlns=xmlns_http_upload})
                     :text(errobj.parameters.invalid_character)));
               return true;
            elseif errobj.err_code == 4 then
               origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg,
                  st.stanza("missing-parameter", {xmlns=xmlns_http_upload})
                     :text(errobj.parameters.missing_parameter)));
               return true;
            else
               origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "unknown err_code"));
               return true;
            end
         elseif statuscode == 403 and errobj["msg"] ~= nil then
            origin.send(st.error_reply(stanza, "cancel", "internal-server-error", errobj.msg));
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
   else
      origin.send(st.error_reply(stanza, "cancel", "undefined-condition", "status code: " .. statuscode .. " response: " ..respbody));
      return true;
   end

   local reply = st.reply(stanza);
   reply:tag("slot", { xmlns = xmlns_http_upload });
   reply:tag("get"):text(get_url):up();
   reply:tag("put"):text(put_url):up();
   module:log("info", "HTTPUPLOAD: get " .. get_url);
   module:log("info", "HTTPUPLOAD: put " .. put_url);
   origin.send(reply);
   return true;
end);
