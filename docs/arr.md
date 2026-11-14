# Radarr & Sonarr

Spotweb can show, next to every movie and series spot, whether the title is
already in your own Radarr (movies) or Sonarr (series) library, and add a
missing title to that library when you download it. The download itself still
goes to SABnzbd; Radarr and Sonarr are only the libraries that manage your
media, so they pick the finished download up and import it.

## What you see

| Label | Meaning |
| --- | --- |
| **Radarr · on disk**, **Sonarr · S3 on disk** | The movie, or that season, is already downloaded. |
| **Radarr · missing**, **Sonarr · S3: 2 missing** | The title is in the library and monitored, but (some of) it is not downloaded yet. |
| **… not monitored** | The title is in the library, but Radarr/Sonarr will not download it. |
| dashed **Not in Radarr** / **Not in Sonarr** | The title is not in your library. Click it to pick the right title, folder and profile and add it. |

On phones the list only shows a coloured dot; the full text is in the tooltip
and on the spot page. The spot page has a card with the same information,
an *Add to* button and a link that opens the title in Radarr or Sonarr.

## When you download

When you download a movie or series spot (SABnzbd, push to SABnzbd or NZBGet):

1. If the title is not in your library yet and Spotweb is sure which title it
   is (IMDb link in the spot, or an exact title + year match), it adds it first.
   Radarr adds the movie without starting its own search. Sonarr adds the
   series with **only the current season monitored**, plus the season of the
   spot when that is an older one, and also without a search.
2. The NZB goes to SABnzbd in the category of that service (by default `movies`
   for Radarr and `tv` for Sonarr) with a clean name, for example
   `Oppenheimer (2023) 1080p WEB-DL` or `Wie is de Mol S25 NL`.
3. When SABnzbd is done, Radarr/Sonarr see the finished download in their
   category and import it.

If Spotweb is not sure which title it is, it does not guess: the download goes
ahead and a notice tells you to use *Add to* on the spot. If Radarr or Sonarr
cannot be reached, the download still goes to SABnzbd.

## Setting it up

**Admin → Settings → API & RSS → Radarr & Sonarr** switches the feature on or
off for the whole site (on by default). Users need the *download integration*
permission.

Every user then connects their own Radarr and/or Sonarr under
**My account → Radarr & Sonarr**:

1. Switch the service on and enter its address, for example
   `http://192.168.1.20:7878` (Radarr) or `http://192.168.1.20:8989` (Sonarr).
   A URL base such as `/radarr` is fine.
2. Paste the API key from Radarr/Sonarr → *Settings → General*.
3. Press **Test connection**. That fills the lists of root folders and quality
   profiles; pick the ones new titles should get.
4. Check the **SABnzbd category**. It must be the category that service uses for
   SABnzbd under *Settings → Download Clients*.
5. Save.

Requirements:

- SABnzbd must be set up as a download client in Radarr and Sonarr, with the
  same categories as in Spotweb.
- **Spotweb connects to Radarr and Sonarr itself**, so the addresses must be
  reachable from the Spotweb server, not just from your browser. If Spotweb runs
  on a public server and Radarr/Sonarr at home, use a VPN, a reverse proxy with
  HTTPS, or a port forward.
- API keys are stored encrypted in the database and are never sent back to the
  browser. Leave the field empty to keep the saved key.

## Speed and caching

Spot lists never call Radarr or Sonarr. Spotweb keeps a copy of each user's
library in the tables `arrlibrary` and `arrsync` and looks up a whole page of
spots with one query. The copy is refreshed in the background after a page has
been sent, at most once per interval (5 minutes to 4 hours, default 15 minutes;
set it under My account). *Fetch now* refreshes it right away, and titles that
Spotweb adds show up at once. The API and RSS feeds for Prowlarr and other
tools are not affected.

Matching uses the title (also the original and alternative titles from
Radarr/Sonarr), ignores capitals, accents, punctuation and a leading
"The"/"De"/"Het", and uses the year when the spot has one. An IMDb link in the
spot always wins.

## Upgrading

The feature needs database schema 0.75 (two new tables). Run
`php bin/upgrade-db.php` after updating, or update through
*Admin → About & updates*, which does it for you. Existing data is kept.
