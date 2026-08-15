<?php
$pageTitle = 'Home';
require_once __DIR__ . '/includes/config.php';

$db = getDB();

$featured = $db->query("
    SELECT m.*, COALESCE(AVG(r.stars), 0) AS avg_rating
    FROM movies m
    LEFT JOIN reviews r ON r.movie_id = m.id AND r.is_approved = 1
    WHERE m.featured = 1 AND m.status = 'now_showing'
    GROUP BY m.id
    ORDER BY m.release_date DESC
    LIMIT 1
")->fetch_assoc();

if (!$featured) {
    $featured = $db->query("
        SELECT m.*, COALESCE(AVG(r.stars), 0) AS avg_rating
        FROM movies m
        LEFT JOIN reviews r ON r.movie_id = m.id AND r.is_approved = 1
        WHERE m.status = 'now_showing'
        GROUP BY m.id
        ORDER BY m.release_date DESC
        LIMIT 1
    ")->fetch_assoc();
}

$now = $db->query("
    SELECT m.*, COALESCE(AVG(r.stars), 0) AS avg_rating
    FROM movies m
    LEFT JOIN reviews r ON r.movie_id = m.id AND r.is_approved = 1
    WHERE m.status = 'now_showing'
    GROUP BY m.id
    ORDER BY m.featured DESC, m.release_date DESC
    LIMIT 8
");

$soon = $db->query("
    SELECT m.*, COALESCE(AVG(r.stars), 0) AS avg_rating
    FROM movies m
    LEFT JOIN reviews r ON r.movie_id = m.id AND r.is_approved = 1
    WHERE m.status = 'coming_soon'
    GROUP BY m.id
    ORDER BY m.release_date ASC
    LIMIT 4
");

$trailers = $db->query("
    SELECT id, title, genre, language, duration_min, poster, trailer_url
    FROM movies
    WHERE trailer_url IS NOT NULL AND trailer_url <> ''
    ORDER BY featured DESC, release_date DESC
    LIMIT 3
");

$popularGenres = $db->query("
    SELECT genre, COUNT(*) AS movie_count
    FROM movies
    WHERE genre IS NOT NULL AND genre <> ''
    GROUP BY genre
    ORDER BY movie_count DESC, genre ASC
    LIMIT 6
");

$featuredCinemas = $db->query("
    SELECT
        c.id,
        c.name,
        c.city,
        COUNT(DISTINCT s.movie_id) AS movie_count,
        COUNT(s.id) AS show_count
    FROM cinemas c
    LEFT JOIN showtimes s
        ON s.cinema_id = c.id
       AND s.show_date >= CURDATE()
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY show_count DESC, c.name ASC
    LIMIT 3
");

$stats = [
    'movies' => (int)$db->query("SELECT COUNT(*) AS c FROM movies WHERE status = 'now_showing'")->fetch_assoc()['c'],
    'cinemas' => (int)$db->query("SELECT COUNT(*) AS c FROM cinemas WHERE is_active = 1")->fetch_assoc()['c'],
    'bookings' => (int)$db->query("SELECT COUNT(*) AS c FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['c'],
    'reviews' => (int)$db->query("SELECT COUNT(*) AS c FROM reviews WHERE is_approved = 1")->fetch_assoc()['c'],
];

$heroTitle = strtoupper((string)($featured['title'] ?? 'Neon Requiem'));
$heroWords = preg_split('/\s+/', $heroTitle) ?: ['NEON', 'REQUIEM'];
$heroEmphasis = array_pop($heroWords) ?: 'REQUIEM';
$heroLead = implode(' ', $heroWords) ?: 'NEON';

require_once __DIR__ . '/includes/header.php';
?>



<div class="home-premium">

<section class="hero hero-3d-stage cinematic-home-hero" data-hero-3d>
    <div class="hero-bg" data-depth-layer="0.12"></div>
    <div class="hero-spotlight" aria-hidden="true"></div>
    <div class="hero-depth-scene" aria-hidden="true">
        <span class="hero-ring hero-ring-one"></span>
        <span class="hero-ring hero-ring-two"></span>
        <span class="hero-ring hero-ring-three"></span>
        <span class="floating-ticket floating-ticket-one">MOVIEPRO</span>
        <span class="floating-ticket floating-ticket-two">ADMIT ONE</span>
        <span class="floating-cube cube-one"></span>
        <span class="floating-cube cube-two"></span>
    </div>

    <div class="container hero-grid">
        <div>
            <div class="eyebrow reveal-cinema">Now playing in cinemas</div>
            <h1><?= e($heroLead) ?><span><?= e($heroEmphasis) ?></span></h1>
            <p class="reveal-cinema"><?= e($featured['description'] ?? 'Discover blockbusters, compare showtimes and reserve your perfect seats.') ?></p>

            <div class="hero-actions reveal-cinema">
                <a class="btn btn-primary magnetic" href="<?= url('movie.php?id=' . (int)($featured['id'] ?? 1)) ?>">Book tickets</a>
                <a class="btn btn-outline magnetic" href="<?= url('movie.php?id=' . (int)($featured['id'] ?? 1)) ?>#trailer">Watch trailer</a>
            </div>

            <div class="hero-stats reveal-cinema" aria-label="Live platform statistics">
                <div class="hero-stat"><strong><?= number_format($stats['movies']) ?>+</strong><span>Movies</span></div>
                <div class="hero-stat"><strong><?= number_format($stats['cinemas']) ?>+</strong><span>Cinemas</span></div>
                <div class="hero-stat"><strong><?= number_format($stats['bookings']) ?>+</strong><span>Bookings</span></div>
            </div>
        </div>

        <?php if ($featured): ?>
            <a class="hero-card reveal-cinema" data-tilt-card data-tilt-strength="12" href="<?= url('movie.php?id=' . (int)$featured['id']) ?>" aria-label="Open featured movie <?= e($featured['title']) ?>">
                <img src="<?= posterUrl($featured['poster']) ?>" alt="<?= e($featured['title']) ?> poster" fetchpriority="high">
                <div class="hero-card-info">
                    <span class="movie-genre">Featured presentation</span>
                    <h3><?= e($featured['title']) ?></h3>
                    <p><?= e($featured['genre']) ?> · <?= (int)$featured['duration_min'] ?> min · <?= e($featured['language']) ?></p>
                    <span class="featured-rating-pill">★ <?= number_format((float)$featured['avg_rating'], 1) ?> audience rating</span>
                </div>
            </a>
        <?php endif; ?>
    </div>

    <a class="hero-scroll-indicator" href="#quick-benefits" aria-label="Scroll to benefits"><span>Scroll to enter</span><i></i></a>
</section>

<section class="section" id="quick-benefits" style="padding-top:0;">
    <div class="container">
        <div class="quick-card-grid">
            <article class="quick-card reveal-cinema" data-tilt-card data-tilt-strength="4">
                <div class="quick-card-icon" aria-hidden="true">⌁</div>
                <h3>Smart Seat Hold</h3>
                <p>Your selected seats stay protected while you finish checkout.</p>
            </article>
            <article class="quick-card reveal-cinema" data-tilt-card data-tilt-strength="4">
                <div class="quick-card-icon" aria-hidden="true">◫</div>
                <h3>Live Seat Map</h3>
                <p>See availability and choose exact Gold, Platinum or Box seats.</p>
            </article>
            <article class="quick-card reveal-cinema" data-tilt-card data-tilt-strength="4">
                <div class="quick-card-icon" aria-hidden="true">★</div>
                <h3>CinePoints Rewards</h3>
                <p>Earn loyalty points and redeem them on future movie nights.</p>
            </article>
            <article class="quick-card reveal-cinema" data-tilt-card data-tilt-strength="4">
                <div class="quick-card-icon" aria-hidden="true">✓</div>
                <h3>Instant QR Ticket</h3>
                <p>Receive a printable digital ticket immediately after booking.</p>
            </article>
        </div>
    </div>
</section>

<section class="section" id="now-showing">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">In cinemas now</div>
                <h2 class="section-title">Now <span class="accent">Showing</span></h2>
            </div>
            <a class="btn btn-secondary magnetic" href="<?= url('movies.php?status=now_showing') ?>">View all movies</a>
        </div>

        <div class="movies-grid">
            <?php while ($movie = $now->fetch_assoc()): ?>
                <a class="movie-card reveal-cinema" data-tilt-card data-tilt-strength="7" data-trailer-embed="<?= e(youtubeEmbed($movie['trailer_url']) ?: '') ?>" href="<?= url('movie.php?id=' . (int)$movie['id']) ?>" aria-label="View <?= e($movie['title']) ?> details">
                    <div class="movie-poster">
                        <img src="<?= posterUrl($movie['poster']) ?>" alt="<?= e($movie['title']) ?> poster" loading="lazy" decoding="async">
                        <span class="movie-badge now">Now Showing</span>
                        <div class="movie-overlay"><span class="play-circle" aria-hidden="true">▶</span></div>
                    </div>
                    <div class="movie-info">
                        <div class="movie-genre"><?= e($movie['genre']) ?></div>
                        <h3><?= e($movie['title']) ?></h3>
                        <div class="movie-meta">
                            <span><?= (int)$movie['duration_min'] ?> min</span>
                            <span><?= e($movie['language']) ?></span>
                            <span class="rating">★ <?= number_format((float)$movie['avg_rating'], 1) ?></span>
                        </div>
                        <div class="movie-card-actions">
                            <span class="movie-card-action">View details</span>
                            <span class="movie-card-action">Book seats</span>
                        </div>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Built for movie night</div>
                <h2 class="section-title">Everything feels <span class="accent">effortless</span></h2>
            </div>
            <p class="section-copy">From finding the right show to entering the theatre, every step is connected through one premium booking flow.</p>
        </div>

        <div class="bento-grid">
            <article class="bento-card large red reveal-cinema" data-tilt-card data-tilt-strength="3">
                <div class="bento-icon" aria-hidden="true">◉</div>
                <h3>Automatic best-seat selection</h3>
                <p>Let MoviePro find centred, contiguous seats for your group, or open the live map and choose every seat manually.</p>
                <div class="bento-tags"><span>Centered view</span><span>Group seating</span><span>Real-time availability</span></div>
                <span class="bento-number" aria-hidden="true">01</span>
            </article>

            <article class="bento-card medium gold reveal-cinema" data-tilt-card data-tilt-strength="3">
                <div class="bento-icon" aria-hidden="true">⌘</div>
                <h3>Multi-movie cart</h3>
                <p>Reserve different shows, seat classes and snack combinations in one organised cart.</p>
                <div class="bento-tags"><span>Timed holds</span><span>Grouped orders</span></div>
                <span class="bento-number" aria-hidden="true">02</span>
            </article>

            <article class="bento-card small reveal-cinema" data-tilt-card data-tilt-strength="3">
                <div class="bento-icon" aria-hidden="true">%</div>
                <h3>Kids concession</h3>
                <p>Children aged 3–12 receive a 50% discount on eligible tickets.</p>
                <span class="bento-number" aria-hidden="true">03</span>
            </article>

            <article class="bento-card small reveal-cinema" data-tilt-card data-tilt-strength="3">
                <div class="bento-icon" aria-hidden="true">♨</div>
                <h3>Snacks & combos</h3>
                <p>Add popcorn, drinks and cinema combos before completing checkout.</p>
                <span class="bento-number" aria-hidden="true">04</span>
            </article>

            <article class="bento-card small reveal-cinema" data-tilt-card data-tilt-strength="3">
                <div class="bento-icon" aria-hidden="true">◆</div>
                <h3>Secure checkout</h3>
                <p>Use supported payment options with server-side seat verification.</p>
                <span class="bento-number" aria-hidden="true">05</span>
            </article>
        </div>
    </div>
</section>

<?php if ($trailers && $trailers->num_rows): ?>
<section class="section">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Watch before you book</div>
                <h2 class="section-title">Latest <span class="accent">Trailers</span></h2>
            </div>
            <p class="section-copy">A first look at the stories currently commanding the big screen.</p>
        </div>

        <div class="trailer-grid">
            <?php while ($trailer = $trailers->fetch_assoc()): ?>
                <a class="trailer-card reveal-cinema" href="<?= url('movie.php?id=' . (int)$trailer['id']) ?>#trailer" data-tilt-card data-tilt-strength="6">
                    <img src="<?= posterUrl($trailer['poster']) ?>" alt="<?= e($trailer['title']) ?> trailer artwork" loading="lazy" decoding="async">
                    <span class="trailer-play" aria-hidden="true">▶</span>
                    <div class="trailer-card-content">
                        <div class="section-tag"><?= e($trailer['genre']) ?></div>
                        <h3><?= e($trailer['title']) ?></h3>
                        <p><?= e($trailer['language']) ?> · <?= (int)$trailer['duration_min'] ?> minutes</p>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($popularGenres && $popularGenres->num_rows): ?>
<section class="section section-alt">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Choose your mood</div>
                <h2 class="section-title">Browse by <span class="accent">genre</span></h2>
            </div>
            <a class="btn btn-secondary magnetic" href="<?= url('movies.php') ?>">Explore all movies</a>
        </div>

        <div class="genre-card-grid">
            <?php $genreIndex = 1; while ($genre = $popularGenres->fetch_assoc()): ?>
                <a class="genre-tile reveal-cinema" href="<?= url('movies.php?genre=' . urlencode($genre['genre'])) ?>">
                    <span class="genre-index"><?= str_pad((string)$genreIndex, 2, '0', STR_PAD_LEFT) ?></span>
                    <strong><?= e($genre['genre']) ?></strong>
                    <span><?= (int)$genre['movie_count'] ?> available title<?= (int)$genre['movie_count'] === 1 ? '' : 's' ?></span>
                </a>
            <?php $genreIndex++; endwhile; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Choose your experience</div>
                <h2 class="section-title">Three premium <span class="accent">seat classes</span></h2>
            </div>
            <p class="section-copy">Select the viewing experience that matches your movie night and budget.</p>
        </div>

        <div class="class-card-grid">
            <article class="class-card reveal-cinema" data-tilt-card data-tilt-strength="4">
                <span class="class-label">Comfort</span>
                <div class="class-icon" aria-hidden="true">G</div>
                <h3>Gold Class</h3>
                <p>Reliable comfort with a clear screen view and excellent value.</p>
                <ul class="class-list">
                    <li>Comfortable theatre seating</li>
                    <li>Great sound and screen visibility</li>
                    <li>Ideal for families and groups</li>
                    <li>Eligible for kids concession</li>
                </ul>
            </article>

            <article class="class-card platinum reveal-cinema" data-tilt-card data-tilt-strength="4">
                <span class="class-label">Popular</span>
                <div class="class-icon" aria-hidden="true">P</div>
                <h3>Platinum Class</h3>
                <p>Enhanced comfort, preferred rows and a more premium cinema experience.</p>
                <ul class="class-list">
                    <li>Preferred centre seating zones</li>
                    <li>Extra comfort and legroom</li>
                    <li>Best balance of view and value</li>
                    <li>Works with smart seat selection</li>
                </ul>
            </article>

            <article class="class-card box-class reveal-cinema" data-tilt-card data-tilt-strength="4">
                <span class="class-label">Luxury</span>
                <div class="class-icon" aria-hidden="true">B</div>
                <h3>Box Class</h3>
                <p>A private-feeling premium section designed for special movie nights.</p>
                <ul class="class-list">
                    <li>Premium viewing position</li>
                    <li>Exclusive seating environment</li>
                    <li>Ideal for couples and occasions</li>
                    <li>Luxury cinema atmosphere</li>
                </ul>
            </article>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Plan the next premiere</div>
                <h2 class="section-title">Coming <span class="accent">Soon</span></h2>
            </div>
            <a class="btn btn-secondary magnetic" href="<?= url('movies.php?status=coming_soon') ?>">Full release calendar</a>
        </div>

        <div class="movies-grid">
            <?php while ($movie = $soon->fetch_assoc()): ?>
                <a class="movie-card reveal-cinema" data-tilt-card data-tilt-strength="7" data-trailer-embed="<?= e(youtubeEmbed($movie['trailer_url']) ?: '') ?>" href="<?= url('movie.php?id=' . (int)$movie['id']) ?>">
                    <div class="movie-poster">
                        <img src="<?= posterUrl($movie['poster']) ?>" alt="<?= e($movie['title']) ?> poster" loading="lazy" decoding="async">
                        <span class="movie-badge soon">Coming Soon</span>
                        <div class="movie-overlay"><span class="play-circle" aria-hidden="true">▶</span></div>
                    </div>
                    <div class="movie-info">
                        <div class="movie-genre"><?= e($movie['genre']) ?></div>
                        <h3><?= e($movie['title']) ?></h3>
                        <div class="movie-meta">
                            <span><?= date('d M Y', strtotime($movie['release_date'])) ?></span>
                            <span><?= e($movie['language']) ?></span>
                        </div>
                        <div class="movie-card-actions">
                            <span class="movie-card-action">Movie details</span>
                            <span class="movie-card-action">Set reminder</span>
                        </div>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>
    </div>
</section>

<?php if ($featuredCinemas && $featuredCinemas->num_rows): ?>
<section class="section">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Big-screen destinations</div>
                <h2 class="section-title">Featured <span class="accent">cinemas</span></h2>
            </div>
            <p class="section-copy">Browse active cinemas with upcoming movies and available showtimes.</p>
        </div>

        <div class="cinema-card-grid">
            <?php while ($cinema = $featuredCinemas->fetch_assoc()): ?>
                <article class="cinema-card reveal-cinema" data-tilt-card data-tilt-strength="3">
                    <div class="cinema-icon"><?= e(strtoupper(substr($cinema['name'], 0, 1))) ?></div>
                    <h3><?= e($cinema['name']) ?></h3>
                    <p class="cinema-city"><?= e($cinema['city']) ?></p>
                    <div class="cinema-meta">
                        <span><?= (int)$cinema['movie_count'] ?> movies</span>
                        <span><?= (int)$cinema['show_count'] ?> upcoming shows</span>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section section-alt">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div>
                <div class="section-tag">Moviegoers love MoviePro</div>
                <h2 class="section-title">Built for better <span class="accent">movie nights</span></h2>
            </div>
            <p class="section-copy"><?= number_format($stats['reviews']) ?>+ approved reviews and <?= number_format($stats['bookings']) ?>+ confirmed bookings reflect a growing cinema community.</p>
        </div>

        <div class="review-card-grid">
            <article class="review-card reveal-cinema">
                <div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div>
                <blockquote>“The cards, trailers and seat map made choosing a film and reserving our family seats genuinely simple.”</blockquote>
                <div class="review-person">
                    <div class="review-avatar">AK</div>
                    <div><strong>Ali Khan</strong><span>Verified customer</span></div>
                </div>
            </article>

            <article class="review-card reveal-cinema">
                <div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div>
                <blockquote>“I added two movies and snacks in one cart, then received both QR tickets immediately after checkout.”</blockquote>
                <div class="review-person">
                    <div class="review-avatar">SF</div>
                    <div><strong>Sarah Fatima</strong><span>CinePoints member</span></div>
                </div>
            </article>

            <article class="review-card reveal-cinema">
                <div class="review-stars" aria-label="5 out of 5 stars">★★★★★</div>
                <blockquote>“Automatic best-seat selection is the feature I now use every time. It saves time and keeps our group together.”</blockquote>
                <div class="review-person">
                    <div class="review-avatar">HU</div>
                    <div><strong>Hamza Usman</strong><span>Frequent moviegoer</span></div>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="final-booking-card reveal-cinema" data-parallax="0.03">
            <div class="section-tag">Your next story is waiting</div>
            <h2>Choose the movie. Claim the seat. Enjoy the moment.</h2>
            <p>Explore the latest releases, compare live showtimes, add snacks and receive your QR ticket in one connected booking journey.</p>
            <div class="final-booking-actions">
                <a class="btn btn-primary magnetic" href="<?= url('movies.php?status=now_showing') ?>">Book a movie now</a>
                <?php if (!isLoggedIn()): ?>
                    <a class="btn btn-outline magnetic" href="<?= url('register.php') ?>">Create free account</a>
                <?php else: ?>
                    <a class="btn btn-outline magnetic" href="<?= url('my_bookings.php') ?>">View my bookings</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
