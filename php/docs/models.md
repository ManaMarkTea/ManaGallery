# 3D model previews

Signed-in members can download original model files and open available GLB
previews. Hovering a model card shows a small rotating preview. The artwork page
has a larger viewer with orbit, zoom and reset controls.

Previews use Three.js. Conversion preserves geometry and basic colors; external
textures are omitted. The original download is unchanged.

## Convert existing 3DS files

From `php/`:

```sh
npm ci
npm run models:convert -- /private/models /private/model-previews
```

The source folder needs a `catalogue.json` with numeric IDs and each original
file's `name`, `bytes` and `sha256`. The converter verifies these before reading
`ID.3ds` and writes `ID.glb` plus `catalogue.json` in the output folder.

After reviewing the conversion report, copy the generated GLBs into
`storage/attachments/models/` and the output catalogue to that folder as
`previews.json`. Keep the original catalogue and files. This is an offline
administrator task, not an upload processing service.

## Change the viewer

Edit `viewer/model-viewer.mjs`, then run `npm run build:viewer`. Commit the
generated bundle and its license notices. CI checks that the bundle matches.
