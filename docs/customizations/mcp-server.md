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
- `list_tree`
- `get_document`
- `search_documents`
- `search_nodes`
- `format_references`
- `create_folder`
- `create_document`
- `update_document`
- `edit_document`
- `rename_node`
- `move_node`

Updates use optimistic concurrency control. Read or list a node first, then pass its integer `revision` value as
`expected_revision`. `get_document` also returns a SHA-256 `content_hash`. The older `expected_updated_at` input
remains accepted by `update_document` for existing clients.

`edit_document` avoids sending a complete long document for small changes. Its `exact_text` mode replaces one
unique old text block and can safely rebase the edit when an unrelated concurrent change occurred. Its
`heading_section` mode replaces the body below one unique Markdown heading; headings inside fenced code blocks
are ignored. A stale heading edit is rejected because the complete old section is not present in the request.

Version conflicts are MCP errors with structured content. They include `error.code = version_conflict` and the
latest document or node metadata. The latest Markdown content is included up to 200,000 characters; larger documents
include a bounded preview and can be fetched with `get_document` before retrying.

`list_tree` recursively lists at most 500 folders and Markdown documents, with a configurable depth of at most
10. Pass `include_files: true` to include editable text, images, PDFs, media, and arbitrary attachments. Existing
clients retain the Markdown-only behavior when this input is omitted. `rename_node` and `move_node` work only on
folders and Markdown documents. Moving a folder into itself or one of its descendants is rejected by the server.
No move, rename, or edit operation exposes deletion.

## Referencing documents and attachments

Use `search_nodes` when an AI needs to cite an existing document or attachment. It searches names across every
file type and searchable content in Markdown or editable text files. Results are bounded and can be filtered by
node type and a vault-absolute `path_prefix`. Every file result contains a server-generated `reference` object:

```json
{
  "path": "/Project/attachments/images/Diagram.png",
  "link": "[Diagram](/Project/attachments/images/Diagram.png)",
  "embed": "![Diagram](/Project/attachments/images/Diagram.png)",
  "recommended": "![Diagram](/Project/attachments/images/Diagram.png)"
}
```

Only images have an `embed` value. The recommended form embeds images and creates a normal link for every other
file. Paths with spaces or Markdown-significant punctuation are escaped by the server, so clients should use the
returned Markdown instead of constructing it themselves.

Use `format_references` to resolve up to 50 known node IDs again immediately before writing. Its `auto` style
embeds images and links other files; `link` always creates a normal link; `embed` is accepted only for images.
Resolving by ID returns the current path after a move or rename. IDs outside the requested vault are reported only
as missing, and internal Markdown references never cross vault boundaries.

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
