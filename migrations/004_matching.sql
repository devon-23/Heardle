-- Apple Music recordings found through the iTunes Search API. See docs/BUILD_SPEC.md §8.

-- One row per iTunes track. track_id is also the Apple Music catalog song ID
-- (music.apple.com/us/song/{track_id} opens the same song; checked 2026-10-30).
CREATE TABLE recordings (
    id               INTEGER PRIMARY KEY,
    track_id         INTEGER NOT NULL UNIQUE,
    track_name       TEXT NOT NULL,
    artist_name      TEXT NOT NULL,
    itunes_artist_id INTEGER,
    collection_name  TEXT,
    release_date     TEXT,
    duration_ms      INTEGER,
    artwork_url      TEXT,
    view_url         TEXT,
    explicit         INTEGER NOT NULL DEFAULT 0
);

-- The best few recordings for a song, best first. Replaced whenever the song is searched again.
CREATE TABLE song_candidates (
    song_id      INTEGER NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    recording_id INTEGER NOT NULL REFERENCES recordings(id),
    rank         INTEGER NOT NULL,
    score        INTEGER NOT NULL,
    kind         TEXT NOT NULL,   -- studio | live | acoustic | remix | demo | instrumental | karaoke | altered | other
    artist_match TEXT NOT NULL,   -- exact | credited | partial | original | none
    title_match  TEXT NOT NULL,   -- exact | qualified | fuzzy
    PRIMARY KEY (song_id, recording_id)
);

-- The recording each song will use. 'chosen' is the user's decision and is never overwritten.
CREATE TABLE song_matches (
    song_id      INTEGER PRIMARY KEY REFERENCES songs(id) ON DELETE CASCADE,
    recording_id INTEGER REFERENCES recordings(id),
    status       TEXT NOT NULL,   -- auto | review | unmatched | chosen
    confidence   TEXT NOT NULL,   -- high | medium | none
    search_terms TEXT,            -- the user's own search, used instead of the automatic ones
    matched_at   TEXT NOT NULL,
    decided_at   TEXT
);

CREATE INDEX song_matches_status ON song_matches(status);
