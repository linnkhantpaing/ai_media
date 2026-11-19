"""UVR separation with bounded scene context, atomic checkpoints and actual inference progress."""
import json
import logging
import math
import os
import shutil
import sys
from pathlib import Path

from audio_separator.separator import Separator
import audio_separator.separator.architectures.mdx_separator as mdx
import soundfile as sf

MODEL = "UVR-MDX-NET-Inst_HQ_3.onnx"


def process_items(items, model_dir, completed=0.0, total=None):
    total = total or sum(item["duration"] for item in items)
    current = {"duration": 0.0, "completed": completed}
    last_report = -1

    def report(fraction=0.0):
        nonlocal last_report
        percent = min(100, math.floor((current["completed"] + current["duration"] * fraction) / max(total, 0.001) * 100))
        if percent > last_report:
            print(f"RECAP_BACKGROUND {percent}", flush=True)
            last_report = percent

    def tracked_chunks(iterable, *args, **kwargs):
        count = len(iterable)
        for index, item in enumerate(iterable):
            yield item
            # Leave room for synthesis, edge trimming and atomic checkpoint publication.
            report((index + 1) / max(count, 1) * 0.98)

    mdx.tqdm = tracked_chunks
    report()
    separator = None
    for item in items:
        source, destination = Path(item["input"]), Path(item["output"])
        if not source.is_file() or not (model_dir / MODEL).is_file():
            raise RuntimeError("Separation input or model is unavailable")
        work = destination.parent / (destination.stem + "-stems")
        work.mkdir(exist_ok=True)
        partial = destination.with_name(destination.name + ".partial.wav")
        current["duration"] = item["duration"]
        try:
            if separator is None:
                separator = Separator(output_dir=str(work), model_file_dir=str(model_dir), output_format="WAV",
                                      output_single_stem="Instrumental", log_level=logging.WARNING, use_soundfile=True)
                separator.load_model(model_filename=MODEL)
            # The loaded architecture keeps its own output_dir; update both for each checkpoint.
            separator.output_dir = str(work)
            separator.model_instance.output_dir = str(work)
            separator.separate(str(source), {"Instrumental": "background"})
            result = work / "background.wav"
            if not result.is_file():
                raise RuntimeError("Instrumental stem was not produced")
            audio, sample_rate = sf.read(str(result), dtype="float32", always_2d=True)
            start = round(item.get("trim_start", 0) * sample_rate)
            count = round(item["duration"] * sample_rate)
            selected = audio[start:start + count]
            if len(selected) != count or selected.shape[1] != 2:
                raise RuntimeError("Instrumental checkpoint duration is invalid")
            sf.write(str(partial), selected, sample_rate, subtype="PCM_16")
            os.replace(partial, destination)
            current["completed"] += current["duration"]
            current["duration"] = 0.0
            report()
        finally:
            if partial.exists():
                partial.unlink()
            shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    if sys.argv[1] == "--manifest":
        manifest = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8-sig"))
        process_items(manifest["items"], Path(sys.argv[3]), manifest["completed_seconds"], manifest["total_seconds"])
    else:
        source, destination, model_dir = map(Path, sys.argv[1:4])
        info = sf.info(str(source))
        process_items([{"input": str(source), "output": str(destination), "trim_start": 0, "duration": info.duration}], model_dir)
