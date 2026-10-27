# Model uploads

Try the included [`building-sheep.glb`](../../samples/models/building-sheep.glb)
(CC0, Kenney), or choose your own `.glb` file on the upload page. A thumbnail is rendered in your browser
before submission. You can replace it with an image of your choice. If WebGL is
unavailable, select a thumbnail manually (up to 2 MiB).

Use binary glTF 2.0 with embedded geometry and PNG/JPEG/WebP textures. Export
without Draco, Meshopt or KTX compression. Limits: 10 MiB per model, one million
vertices, 16 megapixels per texture and 32 megapixels across all textures.
Linked files and external URLs are rejected; export textures inside the GLB.

The original model and generated images are saved in private upload storage.
The model counts toward the member and gallery quotas. Approval, hiding and
deletion work like image uploads. Approved thumbnails can be served as static
files; models are served through the moderated download route. Public GLB previews
are available to signed-out visitors; pending and hidden models remain private.
Original legacy 3DS and material downloads still require sign-in.
The interactive viewer preserves embedded textures and materials. Choose
**Textured wireframe** to overlay triangle edges while keeping those materials.
Visitors can also hover over GLB gallery thumbnails to rotate them.
Hover previews start in textured wireframe; artwork and expanded viewers start
in Materials mode.

No extra hosting service is needed. Browser rendering uses the bundled Three.js
code. Rebuild the two bundles after changing viewer source:

```sh
cd php
npm ci
npm run build:viewer
```

Without JavaScript, select a thumbnail manually along with the GLB.
