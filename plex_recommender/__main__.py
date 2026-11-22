"""Command-line interface: python3 -m plex_recommender <command>."""

from __future__ import annotations

import argparse
import json
import sys

from .config import Settings
from .service import RecommenderService


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="plex_recommender", description="Plex-anbefalinger")
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("overview", help="Biblioteker og statistik")
    sp = sub.add_parser("profile", help="Smagsprofil")
    sp.add_argument("--type", choices=["movie", "show"])
    sp.add_argument("--top", type=int, default=10)
    sp = sub.add_parser("most-watched", help="Mest sete titler")
    sp.add_argument("type", choices=["movie", "show"])
    sp.add_argument("--days", type=int)
    sp.add_argument("--limit", type=int, default=20)
    sub.add_parser("history", help="Seneste afspilninger").add_argument("--limit", type=int, default=30)
    sp = sub.add_parser("unwatched", help="Usete titler du har, rangeret")
    sp.add_argument("type", choices=["movie", "show"])
    sp.add_argument("--genre")
    sp.add_argument("--limit", type=int, default=20)
    sp = sub.add_parser("recommend", help="Nye titler du ikke har (kræver TMDB)")
    sp.add_argument("type", choices=["movie", "show"])
    sp.add_argument("--limit", type=int, default=20)
    sp = sub.add_parser("check", help="Tjek om titler findes i biblioteket")
    sp.add_argument("titles", nargs="+")
    args = p.parse_args(argv)

    svc = RecommenderService(Settings.from_env())
    result = {
        "overview": lambda: svc.library_overview(),
        "profile": lambda: svc.taste_profile(args.type, args.top),
        "most-watched": lambda: svc.most_watched(args.type, args.limit, args.days),
        "history": lambda: svc.recent_history(args.limit),
        "unwatched": lambda: svc.unwatched_in_library(args.type, args.limit, args.genre),
        "recommend": lambda: svc.recommend_new(args.type, args.limit),
        "check": lambda: svc.check_owned(args.titles),
    }[args.cmd]()
    json.dump(result, sys.stdout, ensure_ascii=False, indent=2)
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
