"""Regenerate the site's English guide audio: pip install edge-tts mutagen."""

import asyncio
import hashlib
import json
import re
from pathlib import Path

import edge_tts
from mutagen.mp3 import MP3


async def main():
    directory = Path(__file__).resolve().parents[1] / "e-vote-final-enhanced/audio/voter-guide"
    script = json.loads((directory / "narration.json").read_text(encoding="utf-8"))
    manifest = {"voice": script["voice"], "language": "en-US", "steps": {}}
    for screen, text in script["steps"].items():
        if not re.fullmatch(r"[a-z-]+", screen):
            raise ValueError("Invalid tutorial screen name")
        signature = hashlib.sha256(json.dumps([script["voice"], script["rate"], script["pitch"], text]).encode()).hexdigest()[:12]
        filename = f"{screen}-{signature}.mp3"
        target = directory / filename
        if not target.exists():
            # Generate offline assets once; voters never contact the synthesis service.
            temporary = target.with_suffix(".part")
            try:
                await edge_tts.Communicate(text, script["voice"], rate=script["rate"], pitch=script["pitch"]).save(str(temporary))
                if MP3(temporary).info.length <= 0:
                    raise ValueError("The generated narration is empty")
                temporary.replace(target)
            finally:
                temporary.unlink(missing_ok=True)
        duration = round(MP3(target).info.length, 2)
        manifest["steps"][screen] = {"text": text, "file": filename, "duration": duration}
        print(f"{screen}: {duration}s", flush=True)
    # Publish metadata only after every track is ready.
    temporary_manifest = directory / "manifest.json.part"
    temporary_manifest.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary_manifest.replace(directory / "manifest.json")


if __name__ == "__main__":
    asyncio.run(main())
