#!/usr/bin/env python3
"""
Aikido Video Analysis — analyses YouTube videos of Aikido masters with Gemini AI.

Usage:
    export GEMINI_API_KEY=your_api_key

    # Pass YouTube URLs as arguments:
    python scripts/aikido_analysis.py https://youtube.com/watch?v=ABC123 [URL2 ...]

    # Or load URLs from a plain-text file (one URL per line, # lines are comments):
    python scripts/aikido_analysis.py --urls-file scripts/aikido_urls.txt

    # Skip synthesis (just collect raw observations):
    python scripts/aikido_analysis.py --no-synthesis URL ...

    # Re-analyse already-cached URLs:
    python scripts/aikido_analysis.py --reanalyse URL ...

Output:
    scripts/aikido_raw.json       per-video observations (cached; re-runs skip already-processed URLs)
    scripts/aikido_principles.md  synthesised Aikido principles in human-readable Markdown
"""

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

# ── Model constant — swap here to upgrade ─────────────────────────────────────
GEMINI_MODEL = "gemini-1.5-pro"

SCRIPT_DIR = Path(__file__).parent
RAW_JSON = SCRIPT_DIR / "aikido_raw.json"
PRINCIPLES_MD = SCRIPT_DIR / "aikido_principles.md"

GEMINI_API_BASE = "https://generativelanguage.googleapis.com/v1beta/models"

# ── Prompts ───────────────────────────────────────────────────────────────────

ANALYSIS_PROMPT = """\
You are an expert martial arts analyst specialising in Aikido. Analyse this video of an Aikido practitioner.

Provide a structured analysis covering:

1. TECHNIQUES OBSERVED — name and describe specific Aikido techniques (throws, pins, joint locks, redirections).
2. BODY MECHANICS — footwork, posture, centre of gravity, weight transfer, hip rotation, hand placement.
3. MOVEMENT PATTERNS — recurring motifs such as circular motion, blending, spiralling, leading uke.
4. STRATEGIC INTENT — the strategic logic: redirect force, unbalance the attacker, control ma-ai (spacing/timing).
5. UNDERLYING PRINCIPLES — what core Aikido principles are being demonstrated?

Be specific and observational. Describe exactly what you see. If the video quality is low or the URL is inaccessible, say so clearly.
"""

SYNTHESIS_PROMPT_TEMPLATE = """\
You are an experienced Aikido teacher. Below are detailed observations from {n} Aikido training video(s).

Synthesise these observations into a definitive set of core Aikido principles — the rules that seem to underlie all effective Aikido movement and technique.

Requirements:
- Write a 2-3 sentence introductory paragraph framing what Aikido is and what these principles represent.
- List 8-15 numbered principles. Each principle must be a short practitioner-facing directive — something you would say to a student on the mat (e.g. "Lead with your centre, not your hands").
- After each principle write a Notes paragraph (2-4 sentences): what to watch for in practice, common errors, and why the principle matters.
- Close with a brief "Source Material" note stating how many videos were analysed.

Use this exact Markdown structure:

# Aikido Principles

[Intro paragraph]

## Principles

1. **[Principle name]**
   [Short directive]

   *Notes:* [Explanation]

(repeat for all principles)

## Source Material

[Brief closing note]

---

VIDEO OBSERVATIONS:

{observations}
"""


# ── Gemini REST helpers ───────────────────────────────────────────────────────

def _post_json(url: str, payload: dict) -> dict:
    data = json.dumps(payload).encode()
    req = urllib.request.Request(
        url, data=data, headers={"Content-Type": "application/json"}
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            return json.loads(resp.read())
    except urllib.error.HTTPError as exc:
        body = exc.read().decode(errors="replace")
        raise RuntimeError(f"Gemini API error {exc.code}: {body}") from exc


def gemini_analyse_video(api_key: str, youtube_url: str) -> str:
    """Send a YouTube URL to Gemini for video analysis. Returns the response text."""
    endpoint = f"{GEMINI_API_BASE}/{GEMINI_MODEL}:generateContent?key={api_key}"
    payload = {
        "contents": [{
            "parts": [
                {
                    "fileData": {
                        "mimeType": "video/mp4",
                        "fileUri": youtube_url,
                    }
                },
                {"text": ANALYSIS_PROMPT},
            ]
        }]
    }
    result = _post_json(endpoint, payload)
    return _extract_text(result)


def gemini_synthesise(api_key: str, all_observations: list) -> str:
    """Merge per-video observations and ask Gemini to produce principles."""
    observations_text = "\n\n".join(
        f"--- Video {i + 1}: {obs['url']} ---\n{obs['analysis']}"
        for i, obs in enumerate(all_observations)
    )
    prompt = SYNTHESIS_PROMPT_TEMPLATE.format(
        n=len(all_observations),
        observations=observations_text,
    )
    endpoint = f"{GEMINI_API_BASE}/{GEMINI_MODEL}:generateContent?key={api_key}"
    payload = {
        "contents": [{
            "parts": [{"text": prompt}]
        }]
    }
    result = _post_json(endpoint, payload)
    return _extract_text(result)


def _extract_text(response: dict) -> str:
    try:
        return response["candidates"][0]["content"]["parts"][0]["text"]
    except (KeyError, IndexError) as exc:
        raise RuntimeError(f"Unexpected Gemini response shape: {response}") from exc


# ── Cache helpers ─────────────────────────────────────────────────────────────

def load_raw() -> dict:
    if RAW_JSON.exists():
        with open(RAW_JSON) as f:
            return json.load(f)
    return {}


def save_raw(cache: dict) -> None:
    with open(RAW_JSON, "w") as f:
        json.dump(cache, f, indent=2)
    print(f"  Saved → {RAW_JSON}")


# ── Main ──────────────────────────────────────────────────────────────────────

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Analyse Aikido YouTube videos with Gemini and synthesise principles.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        "urls",
        nargs="*",
        metavar="URL",
        help="YouTube URLs to analyse",
    )
    parser.add_argument(
        "--urls-file",
        metavar="FILE",
        help="Plain-text file of YouTube URLs (one per line; lines starting with # are ignored)",
    )
    parser.add_argument(
        "--no-synthesis",
        action="store_true",
        help="Collect per-video observations only; skip the synthesis step",
    )
    parser.add_argument(
        "--reanalyse",
        action="store_true",
        help="Re-analyse all URLs even if already cached in aikido_raw.json",
    )
    return parser.parse_args()


def collect_urls(args: argparse.Namespace) -> list:
    urls = list(args.urls)
    if args.urls_file:
        path = Path(args.urls_file)
        if not path.exists():
            sys.exit(f"ERROR: --urls-file not found: {path}")
        for line in path.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                urls.append(line)
    return urls


def main() -> None:
    args = parse_args()

    api_key = os.environ.get("GEMINI_API_KEY", "").strip()
    if not api_key:
        sys.exit("ERROR: GEMINI_API_KEY environment variable is not set.")

    urls = collect_urls(args)
    if not urls:
        sys.exit(
            "ERROR: No URLs provided. Pass them as arguments or use --urls-file.\n"
            "Example: python scripts/aikido_analysis.py https://youtube.com/watch?v=ABC123"
        )

    # ── Step 1: per-video analysis (with caching) ─────────────────────────────
    print(f"\nModel: {GEMINI_MODEL}")
    print(f"URLs to process: {len(urls)}\n")

    cache = {} if args.reanalyse else load_raw()
    new_results = 0

    for i, url in enumerate(urls, 1):
        if url in cache:
            print(f"[{i}/{len(urls)}] CACHED  {url}")
            continue

        print(f"[{i}/{len(urls)}] Analysing  {url} ...")
        try:
            analysis = gemini_analyse_video(api_key, url)
            cache[url] = {"url": url, "analysis": analysis}
            save_raw(cache)
            new_results += 1
            print(f"  Done ({len(analysis)} chars)")
        except RuntimeError as exc:
            print(f"  ERROR: {exc}")
            print(
                "  Note: if the error mentions an unsupported URI, Gemini may not be able "
                "to fetch this YouTube URL directly. Try downloading the video locally "
                "(e.g. yt-dlp) and uploading it via the Gemini Files API instead."
            )

    if not cache:
        sys.exit("No observations collected. Cannot synthesise principles.")

    if new_results == 0 and not args.no_synthesis:
        print("\nAll URLs already cached. Running synthesis on existing observations.")

    # ── Step 2: synthesis ─────────────────────────────────────────────────────
    if args.no_synthesis:
        print("\n--no-synthesis flag set; skipping synthesis step.")
        print(f"Raw observations: {RAW_JSON}")
        return

    observations = list(cache.values())
    print(f"\nSynthesising principles from {len(observations)} video(s) ...")

    try:
        principles_md = gemini_synthesise(api_key, observations)
    except RuntimeError as exc:
        sys.exit(f"ERROR during synthesis: {exc}")

    PRINCIPLES_MD.write_text(principles_md)
    print(f"Principles written → {PRINCIPLES_MD}\n")

    # Print a short preview
    preview_lines = principles_md.splitlines()[:10]
    print("─── Preview ───────────────────────────────────────────────────────")
    print("\n".join(preview_lines))
    print("(see full document in aikido_principles.md)")


if __name__ == "__main__":
    main()
