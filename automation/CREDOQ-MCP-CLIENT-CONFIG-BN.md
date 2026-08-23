# CredoQ MCP client configuration

## Endpoint

After the `credoq-mcp-server` plugin is installed and activated, the endpoint is:

```text
https://YOUR-WORDPRESS-HOST.example/wp-json/credoq-mcp/v1/mcp
```

For the current staging site, replace the host with `hgg-offenbach.de` only after the plugin is installed there and a fresh MCP key has been generated.

## Claude Desktop remote configuration

The example file `claude_desktop_credoq_mcp.example.json` uses a remote MCP URL and a header variable. Copy the `mcpServers.credoq` object into Claude Desktop's configuration file. Replace the hostname. Do not replace `${CREDOQ_MCP_KEY}` with a key in a committed file.

If the Claude Desktop version does not expand environment variables in remote headers, use its secure UI for adding a custom MCP server and enter the Authorization header there. The value must be:

```text
Authorization: Bearer YOUR_ONE_TIME_CREDOQ_MCP_KEY
```

## Generic MCP client object

```json
{
  "name": "credoq",
  "transport": "streamable-http",
  "url": "https://YOUR-WORDPRESS-HOST.example/wp-json/credoq-mcp/v1/mcp",
  "headers": {
    "Authorization": "Bearer YOUR_CREDOQ_MCP_KEY"
  }
}
```

Store the key in the client secret store or environment variable. Never put it in Git, a public URL, a screenshot, a WordPress option, or a prompt shared with other users.

## First connection test

The client should perform these calls in order:

```text
initialize
notifications/initialized
tools/list
```

Then call a read-only tool first:

```text
credoq_system_status
credoq_plugin_inventory
credoq_list_settings
credoq_list_bookings
credoq_list_services
credoq_list_seat_plans
```

The booking, service, and seat list tools use bounded read-only queries. Their proposal tools return a before/requested-change preview and do not mutate database rows.

## Write safety

Settings updates require `credoq_preview_setting_update`, followed by `credoq_apply_setting_update` with `confirm=true` and the one-time confirmation token. Booking, service, and seat proposal tools are currently preview-only until their typed repository mutation handlers receive a separate review and test pass. Plugin installation, deletion, lifecycle operations, real order creation, deployment, and rollback are not enabled by this configuration.

## Installation checklist

Install `dist/credoq-mcp-server-0.1.1.zip` from WordPress Dashboard → Plugins → Add New → Upload Plugin, activate it, open CredoQ → MCP Connection, generate a key, and copy it once. Add the endpoint and header to Claude Desktop or the selected MCP client. Run the read-only calls above and inspect the WordPress MCP audit log. Rotate the key immediately if it is exposed.
