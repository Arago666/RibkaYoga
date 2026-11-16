"""Run in Blender with --background --python, source FBX path after --."""
import bpy,sys
from pathlib import Path
source=Path(sys.argv[sys.argv.index('--')+1]).resolve()
bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.fbx(filepath=str(source))
for image in bpy.data.images:
 if image.size[0] and image.size[0]>1024:
  image.scale(1024,1024)
for material in bpy.data.materials:
 if not material.use_nodes:continue
 shader=next((n for n in material.node_tree.nodes if n.type=='BSDF_PRINCIPLED'),None)
 if shader:
  for channel in ['Metallic','Roughness','Normal']:
   for link in list(shader.inputs[channel].links):material.node_tree.links.remove(link)
  shader.inputs['Metallic'].default_value=0
  shader.inputs['Roughness'].default_value=.75
bpy.ops.export_scene.gltf(filepath=str(Path('public/models/yoga-human-v1/model.glb').resolve()),export_format='GLB',export_animations=False,export_image_format='JPEG',export_jpeg_quality=88,export_yup=True)
