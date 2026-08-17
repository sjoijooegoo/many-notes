# Image paste and drop

The jcrEw Note edition can upload images directly from a Markdown note instead of requiring a
separate file import first.

## Usage

While the note is editable:

- paste a screenshot or copied image with `Ctrl + V`;
- drag one or more image files into the visual editor; or
- paste or drag images into the raw Markdown editor.

Supported extensions are PNG, JPG/JPEG, GIF, WebP, and AVIF. After a successful upload, Many Notes
inserts the image at the current caret or drop position.

## Storage behavior

An uploaded image becomes a regular `VaultNode` under an `attachments/images` folder beside the
current note. Missing folders are created automatically. For example:

```text
jcrEw Vault/CodexNote/Guide.md
jcrEw Vault/CodexNote/attachments/images/20260817-132455-384-a7c2f9.png
```

New filenames use the browser's local date and time, milliseconds, and a random suffix to remain
readable while avoiding collisions during multi-image or rapid successive pastes. Existing images
are not moved or renamed.

With the production bind mounts in this repository, the physical host path follows this pattern:

```text
/srv/many-notes/private/vaults/<user-id>/<vault-name>/<note-folder>/attachments/images/<image-name>
```

Many Notes generates a non-conflicting filename when a file with the same name already exists.

## Deletion and sizing

Removing an image from the document removes only its Markdown reference. It does not delete the
underlying image because another note may still reference it. Delete the image node from the vault
file tree to remove both its database record and physical file.

Deleting a note does not delete sibling images. Deleting their containing folder removes everything
inside that folder.

Images currently use standard Markdown image syntax, which has no portable width or height fields.
This customization does not yet add resize handles or persist custom dimensions.

## Implementation

- `resources/js/composables/useVaultImageUpload.ts` validates and uploads image files through the
  existing vault import endpoint.
- `resources/js/composables/useEditor.ts` handles paste and drop events in the Tiptap visual editor.
- `resources/js/components/vault/VaultFileNote.vue` handles paste and drop events in raw Markdown
  mode.

The existing backend import authorization, validation, file naming, database persistence, and vault
tree update flow are reused.
