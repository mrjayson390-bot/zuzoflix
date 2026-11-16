-- Zuzoflix tables. {PK} becomes the right auto-number column for MariaDB or SQLite (install.php).
CREATE TABLE IF NOT EXISTS admins (
  id {PK},
  email VARCHAR(190) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS login_attempts (
  id {PK},
  ip VARCHAR(64) NOT NULL,
  at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS movies (
  id {PK},
  slug VARCHAR(100) NOT NULL UNIQUE,
  type VARCHAR(10) NOT NULL DEFAULT 'movie',      -- movie | series
  title VARCHAR(190) NOT NULL,
  description TEXT,
  year VARCHAR(4) DEFAULT '',
  language VARCHAR(60) DEFAULT '',
  category VARCHAR(60) DEFAULT '',
  translator VARCHAR(80) DEFAULT '',              -- the VJ / narrator shown on the card
  poster_key VARCHAR(255) DEFAULT '',
  video_key VARCHAR(255) DEFAULT '',              -- movies only; series use the episodes table
  duration_min INT DEFAULT 0,
  access VARCHAR(10) NOT NULL DEFAULT 'free',     -- free | premium (premium is only enforced once payments are switched on)
  status VARCHAR(10) NOT NULL DEFAULT 'draft',    -- draft | published
  featured INT NOT NULL DEFAULT 0,
  views INT NOT NULL DEFAULT 0,
  rights_holder VARCHAR(190) DEFAULT '',          -- optional
  rights_type VARCHAR(40) DEFAULT '',             -- optional: own | licensed | public-domain | other
  rights_notes TEXT,                              -- optional
  created_at VARCHAR(19) NOT NULL,
  updated_at VARCHAR(19) NOT NULL
);
CREATE INDEX idx_movies_status ON movies (status, created_at);
CREATE INDEX idx_movies_cat ON movies (category);
CREATE TABLE IF NOT EXISTS submissions (          -- films offered by filmmakers through the public "Submit your film" page
  id {PK},
  name VARCHAR(120) NOT NULL,
  contact VARCHAR(190) NOT NULL,
  title VARCHAR(190) NOT NULL,
  year VARCHAR(4) DEFAULT '',
  language VARCHAR(60) DEFAULT '',
  category VARCHAR(60) DEFAULT '',
  description TEXT,
  video_url TEXT,
  youtube_url TEXT,
  poster_url TEXT,
  notes TEXT,
  confirmed INT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'new',      -- new | approved | rejected
  ip VARCHAR(64) DEFAULT '',
  created_at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS reports (              -- copyright or other problems reported by anyone
  id {PK},
  film_url VARCHAR(300) DEFAULT '',
  name VARCHAR(120) DEFAULT '',
  contact VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'new',      -- new | done
  ip VARCHAR(64) DEFAULT '',
  created_at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS tmdb_cache (            -- TMDB answers kept for 3 days, so we call them rarely
  tmdb_id INT NOT NULL PRIMARY KEY,
  body TEXT NOT NULL,
  fetched_at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS movie_sources (
  id {PK},
  movie_id INT NOT NULL,
  label VARCHAR(20) NOT NULL,                     -- 1080p, 720p, 480p, 360p, Low data ...
  height INT NOT NULL DEFAULT 0,                  -- used to order the menu and for Auto
  video_key VARCHAR(255) NOT NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX idx_sources_movie ON movie_sources (movie_id, height);
CREATE TABLE IF NOT EXISTS imports (
  id {PK},
  url TEXT NOT NULL,
  poster_url TEXT,
  title VARCHAR(190) NOT NULL,
  category VARCHAR(60) DEFAULT '',
  translator VARCHAR(80) DEFAULT '',
  language VARCHAR(60) DEFAULT '',
  access VARCHAR(10) NOT NULL DEFAULT 'free',
  publish INT NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'queued',   -- queued | running | done | failed
  done_bytes BIGINT NOT NULL DEFAULT 0,
  total_bytes BIGINT NOT NULL DEFAULT 0,
  error TEXT,
  movie_id INT NOT NULL DEFAULT 0,
  admin_email VARCHAR(190) DEFAULT '',
  created_at VARCHAR(19) NOT NULL,
  updated_at VARCHAR(19) NOT NULL
);
CREATE TABLE IF NOT EXISTS episodes (
  id {PK},
  movie_id INT NOT NULL,
  num INT NOT NULL DEFAULT 1,
  title VARCHAR(190) DEFAULT '',
  video_key VARCHAR(255) NOT NULL,
  created_at VARCHAR(19) NOT NULL
);
CREATE INDEX idx_episodes_movie ON episodes (movie_id, num);
