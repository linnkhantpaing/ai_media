import logging
import os
import shutil
import sys
from pathlib import Path
from audio_separator.separator import Separator

source, destination, model_dir = map(Path, sys.argv[1:4])
model = "UVR-MDX-NET-Inst_HQ_3.onnx"
if not source.is_file() or not (model_dir / model).is_file():
    raise SystemExit("Separation input or model is unavailable")
work = destination.parent / (destination.stem + "-stems")
work.mkdir(exist_ok=True)
try:
    separator = Separator(output_dir=str(work), model_file_dir=str(model_dir),
                          output_format="WAV", output_single_stem="Instrumental",
                          log_level=logging.WARNING, use_soundfile=True)
    separator.load_model(model_filename=model)
    outputs = separator.separate(str(source), {"Instrumental": "background"})
    result = work / "background.wav"
    if not result.is_file():
        raise RuntimeError("Instrumental stem was not produced")
    os.replace(result, destination)
finally:
    shutil.rmtree(work, ignore_errors=True)
