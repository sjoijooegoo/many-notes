# Remote MCP server

The jcrEw Note edition exposes a remote MCP endpoint at:

```text
https://mcp.jcrewnote.top/mcp
```

Access requires a Sanctum bearer token. Every token is scoped to one vault and can be read-only or read/write.
The server never exposes a document or file deletion tool.

## Available tools

- `list_vaults`
- `list_documents`
- `get_document`
- `search_documents`
- `create_folder`
- `create_document`
- `update_document`

Updates use optimistic concurrency control: call `get_document` first, then pass its `updated_at` value as
`expected_updated_at`. The update is rejected if another client changed the document in the meantime.

## Manage tokens in the web interface

Open a vault, select the menu beside its name, and choose **MCP API tokens**. The modal lets the current user:

- copy the MCP endpoint;
- create a named token with an expiration date;
- keep reading limited to the current vault, or explicitly allow reading every vault visible to the account;
- allow creating and updating notes only in the currently open vault;
- review creation, last-used, and expiration times; and
- revoke one of their own tokens immediately.

The all-visible-vaults option is dynamic: it includes accepted collaboration vaults and vaults the user creates or
joins later. Existing vault policies are checked on every request, so revoking collaboration access also removes
the token's access to that vault.

The plaintext token is displayed only once after creation. The interface never exposes document deletion and
never reveals an existing token secret.

## Create a token from the command line

Run the command in the production container, replacing the email address and vault ID:

```bash
docker exec -it many-notes-many-notes-1 \
    php artisan mcp:token:create user@example.com 1 --name="My AI client"
```

Add `--read-only` when the client should only list, search, and read documents. The plaintext token is displayed
only once, so store it in the client's secret storage instead of committing it to a repository.

Add `--read-all-vaults` to allow reading every vault currently or subsequently visible to the account. This does
not grant write access to those other vaults.

List token metadata without revealing secrets:

```bash
docker exec -it many-notes-many-notes-1 \
    php artisan mcp:token:list user@example.com
```

Revoke a token by its ID:

```bash
docker exec -it many-notes-many-notes-1 \
    php artisan mcp:token:revoke user@example.com TOKEN_ID
```

## Client settings

Configure a streamable HTTP MCP server with the endpoint above and this header:

```text
Authorization: Bearer YOUR_TOKEN
```

A generic client representation looks like this:

```json
{
  "url": "https://mcp.jcrewnote.top/mcp",
  "headers": {
    "Authorization": "Bearer YOUR_TOKEN"
  }
}
```

Do not put a real token in a shared configuration file. Prefer environment-variable expansion or the client's
secret manager when supported.

## Deployment boundary

`MCP_HOST` controls the accepted MCP hostname. Caddy exposes only `/mcp` on that hostname and returns `404` for
other paths. Laravel applies Sanctum authentication and request rate limiting before dispatching MCP requests;
tool handlers then enforce both the token's vault abilities and the existing Many Notes vault policy.
