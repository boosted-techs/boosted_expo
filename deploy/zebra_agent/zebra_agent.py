#!/usr/bin/env python3
"""Repository entry point; the downloadable helper is bundled with the application."""
import pathlib
import runpy

if __name__ == "__main__":
    runpy.run_path(str(pathlib.Path(__file__).resolve().parents[2] / "backend/resources/printing/zebra_agent.py"), run_name="__main__")
