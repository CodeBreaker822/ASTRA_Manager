# CMS and SEO Content Deployment

The SEO content is source-controlled and published to the database by
`Database\Seeders\SeoContentSeeder`.

It safely creates or completes:

- Shared header, navigation, footer, pricing-fact, and search content
- Home, Audio to Text, Features, Pricing, Download, and Blog page content
- The focused `/audio-to-text` landing page
- Blog landing and article-layout copy
- Evergreen posts from `resources/blog/*.md`

It does **not** create users, change plans, alter transcription processing, or
change charging records.

## Production deployment

Run these commands from the Laravel project directory after deploying the new
code:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build

php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\SeoContentSeeder' --force
php artisan optimize
```

`optimize:clear` must run before the seeder so Laravel reads the new marketing
configuration instead of an old cached copy. If frontend assets are built in CI
and `public/build` is deployed with the release, omit `npm ci` and
`npm run build` on the server.

The seeder does not require Supervisor or a queue worker. It runs immediately
inside the Artisan command. Queue processes used by transcription are a
separate runtime concern.

Do not run the unqualified `DatabaseSeeder` in production for this update. That
seeder currently creates `test@example.com`. Use `SeoContentSeeder` exactly as
shown above.

## Safe reruns and CMS edits

The SEO seeder is idempotent and CMS-safe:

- Existing page values edited in the dashboard are preserved.
- Newly introduced fields or sections are added with their shipped defaults.
- Existing blog posts, including seeded posts edited in Blog Manager, are not
  overwritten or resurrected after deletion.
- Missing source-backed blog posts are created once.
- Dashboard-created posts are not removed or changed.

This means it is safe to rerun the exact seeder command after later deployments.
Changing a default in `config/marketing.php` or a seeded Markdown file does not
replace an existing database edit; make intentional content changes through the
CMS.

## Editing after deployment

- Open **Dashboard → Page Content** for the header/footer and every public page.
- Choose a page, edit its human-readable sections, and click **Save changes**.
- Use **View page** to open the public result in a new tab.
- Open **Blog Manager** for article titles, excerpts, covers, publication state,
  and Markdown bodies.
- Open **Pricing Manager** for actual rates, allowances, plan names, and plan
  features. Public pricing-page headings and labels live under **Page Content →
  Pricing page**.

## Verification

After seeding, check:

```bash
php artisan db:show
php artisan route:list --path=audio-to-text
php artisan route:list --path=blog
```

Then open these public URLs:

- `/audio-to-text`
- `/blog`
- `/blog/how-to-convert-audio-to-text`
- `/blog/how-to-convert-mp3-to-text`
- `/sitemap.xml`

Confirm production `APP_URL` is the canonical HTTPS domain before caching the
configuration. The sitemap, canonical URLs, and structured data use that value.

## Search indexing follow-up

After deployment:

1. Submit `/sitemap.xml` in Google Search Console.
2. Inspect `/audio-to-text` and request indexing if it is not known yet.
3. Inspect the pillar guide `/blog/how-to-convert-audio-to-text`.
4. Validate the landing page and an article with Google's Rich Results Test.
5. Monitor impressions and queries before changing titles again; ranking is not
   immediate or guaranteed.

## Search-intent map

| URL | Primary intent |
| --- | --- |
| `/` | JERVA brand and online/offline transcription overview |
| `/audio-to-text` | Audio-to-text converter and commercial evaluation |
| `/features` | Product feature comparison |
| `/download` | Offline Windows audio-to-text app |
| `/blog/how-to-convert-audio-to-text` | Informational audio-to-text guide |
| `/blog/how-to-convert-mp3-to-text` | MP3-to-text workflow |
| `/blog/meeting-recording-to-text` | Meeting transcription and minutes |
| `/blog/interview-transcription-guide` | Interview transcription workflow |
| `/blog/improve-audio-transcription-accuracy` | Recording and review quality |
| `/blog/audio-file-formats-for-transcription` | Audio format comparison |
| `/blog/web-vs-desktop` | Online versus offline transcription |
