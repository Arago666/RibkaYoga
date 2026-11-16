# Yoga human v1

Source supplied by the owner on 10 November 2026: `tripo_convert_41535ff1-9bc9-4673-9a96-534bc75211da.zip`. The FBX contains a Mixamo skeleton with 65 bones and a skinned mesh with 10,118 vertices and 20,241 polygons.

`prepare.py` imports the FBX in Blender and exports the fixed version `public/models/yoga-human-v1/model.glb`. Color textures are limited to 1024 pixels. Metallic, normal and roughness maps are replaced with simple material settings. The resulting GLB is about 1.5 MiB. Keep this version immutable because saved poses reference its bone geometry.

Pose JSON and transparent PNG 1024 × 1024 are saved together through the revision-checked Catalog API. Replacing the image normally clears the pose source. The seven presets are editable starting points. The editor supports manual joint rotation and short IK chains for hands and feet. It does not calculate whole-body balance, collisions or anatomical correctness.
