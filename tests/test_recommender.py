import time

from plex_recommender.config import Settings
from plex_recommender.models import MOVIE, SHOW, normalize_title
from plex_recommender.plex import parse_item
from plex_recommender.service import RecommenderService

NOW = int(time.time())


def movie(key, title, year, genres, views=0, directors=(), actors=(), tmdb=None, rating=None):
    meta = {
        "ratingKey": key, "title": title, "year": year, "type": "movie",
        "Genre": [{"tag": g} for g in genres],
        "Director": [{"tag": d} for d in directors],
        "Role": [{"tag": a} for a in actors],
        "viewCount": views, "lastViewedAt": NOW - 86400 if views else None,
        "audienceRating": rating,
    }
    if tmdb:
        meta["Guid"] = [{"id": f"tmdb://{tmdb}"}, {"id": "imdb://tt0000001"}]
    return parse_item(meta, "Film")


def show(key, title, year, genres, watched, total, tmdb=None):
    return parse_item(
        {
            "ratingKey": key, "title": title, "year": year, "type": "show",
            "Genre": [{"tag": g} for g in genres], "leafCount": total,
            "viewedLeafCount": watched, "lastViewedAt": NOW - 3600 if watched else None,
            "guid": f"com.plexapp.agents.thetvdb://{tmdb}?lang=en" if tmdb else "",
        },
        "Serier",
    )


LIBRARY = {
    MOVIE: [
        movie("1", "Blade Runner 2049", 2017, ["Science Fiction", "Drama"], views=3,
              directors=["Denis Villeneuve"], actors=["Ryan Gosling"], tmdb=335984),
        movie("2", "Arrival", 2016, ["Science Fiction", "Drama"], views=2,
              directors=["Denis Villeneuve"], tmdb=329865),
        movie("3", "Notting Hill", 1999, ["Romance", "Comedy"], views=1),
        movie("4", "Dune", 2021, ["Science Fiction", "Adventure"], directors=["Denis Villeneuve"],
              tmdb=438631, rating=8.0),
        movie("5", "Love Actually", 2003, ["Romance", "Comedy"], rating=7.0),
        movie("6", "Drive", 2011, ["Drama", "Thriller"], actors=["Ryan Gosling"]),
    ],
    SHOW: [
        show("10", "Severance", 2022, ["Science Fiction", "Drama"], 19, 19, tmdb=371980),
        show("11", "Friends", 1994, ["Comedy"], 2, 236),
        show("12", "Dark", 2017, ["Science Fiction", "Mystery"], 0, 26),
    ],
}


class FakePlex:
    def libraries(self):
        return [{"key": "1", "title": "Film", "type": "movie"}, {"key": "2", "title": "Serier", "type": "show"}]

    def library_items(self, media_type):
        return LIBRARY[media_type]

    def history(self, limit=100, since=None, account_id=None):
        return [
            {"type": "episode", "grandparentRatingKey": "10", "grandparentTitle": "Severance",
             "parentIndex": 2, "index": 10, "title": "Cold Harbor", "viewedAt": NOW - 100},
            {"type": "episode", "grandparentRatingKey": "10", "viewedAt": NOW - 200},
            {"type": "episode", "grandparentRatingKey": "11", "viewedAt": NOW - 300},
            {"type": "movie", "ratingKey": "1", "title": "Blade Runner 2049", "year": 2017, "viewedAt": NOW - 400},
        ][:limit]


class FakeTMDB:
    @staticmethod
    def kind(t):
        return "movie" if t == MOVIE else "tv"

    def genre_names(self, media_type):
        return {878: ["Science Fiction"], 18: ["Drama"], 10749: ["Romance"], 35: ["Comedy"]}

    def find_tmdb_id(self, media_type, imdb_id, tvdb_id):
        return None

    def recommendations(self, media_type, tmdb_id, pages=1):
        if media_type == SHOW:
            return []
        return [
            {"id": 438631, "title": "Dune", "release_date": "2021-09-15", "vote_count": 9000,
             "vote_average": 7.8, "genre_ids": [878]},  # owned -> filtered out
            {"id": 1, "title": "Sicario", "release_date": "2015-09-17", "vote_count": 8000,
             "vote_average": 7.4, "genre_ids": [18]},
            {"id": 2, "title": "Ex Machina", "release_date": "2015-01-21", "vote_count": 12000,
             "vote_average": 7.6, "genre_ids": [878, 18]},
            {"id": 3, "title": "Obscure Thing", "release_date": "2020-01-01", "vote_count": 3,
             "vote_average": 9.0, "genre_ids": [878]},  # too few votes
            {"id": 4, "title": "Rom-com Deluxe", "release_date": "2019-01-01", "vote_count": 500,
             "vote_average": 6.0, "genre_ids": [10749, 35]},
        ]


def svc(tmdb=True):
    s = Settings(plex_url="http://x", plex_token="t")
    return RecommenderService(s, plex=FakePlex(), tmdb=FakeTMDB() if tmdb else None)


def test_parse_guids():
    m = LIBRARY[MOVIE][0]
    assert m.tmdb_id == "335984" and m.imdb_id == "tt0000001"
    assert LIBRARY[SHOW][0].tvdb_id == "371980"


def test_normalize_title():
    assert normalize_title("The Lord of the Rings: The Two Towers") == "lord of the rings the two towers"
    assert normalize_title("Amélie") == "amelie"


def test_profile_prefers_sci_fi():
    prof = svc().taste_profile()
    assert prof["top_genres"][0]["name"] == "science fiction"
    assert prof["top_directors"][0]["name"] == "Denis Villeneuve"
    # A fully watched show ranks above an abandoned one
    titles = [t["title"] for t in prof["most_engaged_titles"]]
    assert titles.index("Severance") < titles.index("Friends")


def test_unwatched_ranking():
    recs = svc().unwatched_in_library(MOVIE)
    titles = [r["title"] for r in recs]
    assert titles[0] == "Dune"
    assert "Blade Runner 2049" not in titles
    assert any("Denis Villeneuve" in w for w in recs[0]["why"])
    assert [r["title"] for r in svc().unwatched_in_library(SHOW)] == ["Dark"]
    assert "Friends" in [r["title"] for r in svc().unwatched_in_library(SHOW, include_in_progress=True)]


def test_unwatched_genre_filter():
    assert [r["title"] for r in svc().unwatched_in_library(MOVIE, genre="romance")] == ["Love Actually"]


def test_recommend_new_filters_owned_and_low_votes():
    out = svc().recommend_new(MOVIE)
    titles = [r["title"] for r in out["recommendations"]]
    assert "Dune" not in titles and "Obscure Thing" not in titles
    assert titles[0] == "Ex Machina"
    assert titles.index("Ex Machina") < titles.index("Rom-com Deluxe")
    assert out["recommendations"][0]["because_you_watched"]


def test_recommend_new_without_tmdb():
    assert "error" in svc(tmdb=False).recommend_new(MOVIE)


def test_check_owned():
    res = {r["query"]: r for r in svc().check_owned(["Dune (2021)", "Severence", "Ex Machina", "Dune (1984)"])}
    assert res["Dune (2021)"]["in_library"] and res["Dune (2021)"]["status"] == "unwatched"
    assert res["Severence"]["in_library"] and res["Severence"]["status"] == "watched"  # fuzzy
    assert not res["Ex Machina"]["in_library"]
    assert not res["Dune (1984)"]["in_library"]


def test_most_watched_recent_and_history():
    s = svc()
    recent = s.most_watched(SHOW, days=30)
    assert recent[0]["title"] == "Severance" and recent[0]["plays_in_period"] == 2
    hist = s.recent_history(2)
    assert hist[0]["title"].startswith("Severance S02E10")


def test_mcp_server_registers_tools():
    import asyncio
    from plex_recommender import server

    tools = asyncio.run(server.mcp.list_tools())
    names = {t.name for t in tools}
    assert {"taste_profile", "unwatched_in_library", "recommend_new", "check_owned"} <= names
