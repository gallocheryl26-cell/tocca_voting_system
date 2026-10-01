# English tutorial narration

These MP3 files are generated English narration, served by this site. Each voting step has a conversational script independent of its short on-screen description. The default narrator is Microsoft Jenny Neural at a slightly relaxed pace.

Edit `narration.json`, then run:

```sh
python -m pip install edge-tts mutagen
python scripts/generate_voter_narration.py
```

Generation uses [edge-tts](https://github.com/rany2/edge-tts). Python and the synthesis service are only needed when regenerating assets. Deploy this entire folder with the PHP and JavaScript changes. The player uses `manifest.json` for transcripts, track filenames, and durations; filenames change when the script or voice settings change to avoid stale cached audio.

The guide waits for narration to finish before moving on. If an audio file is unavailable, it uses an English device voice when supported. If sound cannot play, the visual guide continues and offers a sound retry. Closing, pausing, or leaving the page stops audio.
