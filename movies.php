<?php

$pageTitle = 'Movies';

require_once __DIR__ . '/includes/config.php';

$db = getDB();

/*
|--------------------------------------------------------------------------
| Get and validate filters
|--------------------------------------------------------------------------
*/

$status = (string)($_GET['status'] ?? '');
$genre  = trim((string)($_GET['genre'] ?? ''));
$query  = trim((string)($_GET['q'] ?? ''));

$allowedStatuses = [
    '',
    'now_showing',
    'coming_soon',
    'ended'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| Build movies query
|--------------------------------------------------------------------------
*/

$where  = ['1 = 1'];
$values = [];
$types  = '';

if ($status !== '') {
    $where[]  = 'm.status = ?';
    $values[] = $status;
    $types   .= 's';
}

if ($genre !== '') {
    $where[]  = 'm.genre = ?';
    $values[] = $genre;
    $types   .= 's';
}

if ($query !== '') {
    $where[] = '
        (
            m.title LIKE ?
            OR m.description LIKE ?
        )
    ';

    $like = '%' . $query . '%';

    $values[] = $like;
    $values[] = $like;
    $types   .= 'ss';
}

$sql = "
    SELECT
        m.*,
        COALESCE(AVG(r.stars), 0) AS avg_rating
    FROM movies AS m
    LEFT JOIN reviews AS r
        ON r.movie_id = m.id
        AND r.is_approved = 1
    WHERE " . implode(' AND ', $where) . "
    GROUP BY m.id
    ORDER BY
        FIELD(
            m.status,
            'now_showing',
            'coming_soon',
            'ended'
        ),
        m.release_date DESC
";

$stmt = $db->prepare($sql);

/*
|--------------------------------------------------------------------------
| Bind parameters — compatible with PHP 8.0
|--------------------------------------------------------------------------
*/

if (!empty($values)) {
    $bindParams = [];

    // First parameter of bind_param() is the data-types string.
    $bindParams[] = $types;

    // mysqli bind_param requires values to be passed by reference.
    foreach ($values as $key => $value) {
        $bindParams[] = &$values[$key];
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindParams
    );
}

$stmt->execute();

$movies = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Load genres
|--------------------------------------------------------------------------
*/

$genres = $db->query("
    SELECT DISTINCT genre
    FROM movies
    WHERE genre IS NOT NULL
      AND genre <> ''
    ORDER BY genre ASC
");

require_once __DIR__ . '/includes/header.php';

?>

<section class="page-hero">
    <div class="container">
        <div class="section-tag">
            Movie catalogue
        </div>

        <h1>
            Find your next
            <span class="accent">movie.</span>
        </h1>

        <p>
            Search the complete catalogue and filter by status or genre.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">

        <form class="filters" method="get" action="">

            <input
                class="form-control"
                type="search"
                name="q"
                value="<?= e($query) ?>"
                placeholder="Search movie title..."
                autocomplete="off"
            >

            <select class="form-control" name="status">
                <option value="">
                    All status
                </option>

                <option
                    value="now_showing"
                    <?= $status === 'now_showing' ? 'selected' : '' ?>
                >
                    Now Showing
                </option>

                <option
                    value="coming_soon"
                    <?= $status === 'coming_soon' ? 'selected' : '' ?>
                >
                    Coming Soon
                </option>

                <option
                    value="ended"
                    <?= $status === 'ended' ? 'selected' : '' ?>
                >
                    Ended
                </option>
            </select>

            <select class="form-control" name="genre">
                <option value="">
                    All genres
                </option>

                <?php while ($g = $genres->fetch_assoc()): ?>
                    <option
                        value="<?= e($g['genre']) ?>"
                        <?= $genre === $g['genre'] ? 'selected' : '' ?>
                    >
                        <?= e($g['genre']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <button class="btn btn-primary" type="submit">
                Filter
            </button>

            <?php if (
                $query !== ''
                || $status !== ''
                || $genre !== ''
            ): ?>
                <a
                    class="btn btn-secondary"
                    href="<?= url('movies.php') ?>"
                >
                    Clear
                </a>
            <?php endif; ?>

        </form>

        <?php if ($movies->num_rows > 0): ?>

            <div class="movies-grid">

                <?php while ($movie = $movies->fetch_assoc()): ?>

                    <?php
                    $movieStatus = (string)$movie['status'];

                    $badgeClass = match ($movieStatus) {
                        'now_showing' => 'now',
                        'coming_soon' => 'soon',
                        default       => 'ended'
                    };

                    $statusLabel = ucwords(
                        str_replace('_', ' ', $movieStatus)
                    );

                    $trailerEmbed = youtubeEmbed(
                        $movie['trailer_url'] ?? ''
                    );
                    ?>

                    <a
                        class="movie-card reveal"
                        href="<?= url(
                            'movie.php?id=' . (int)$movie['id']
                        ) ?>"
                        data-trailer-embed="<?= e($trailerEmbed ?: '') ?>"
                    >

                        <div class="movie-poster">

                            <img
                                src="<?= posterUrl(
                                    $movie['poster'] ?? ''
                                ) ?>"
                                alt="<?= e($movie['title']) ?> poster"
                                loading="lazy"
                                decoding="async"
                            >

                            <span class="movie-badge <?= e($badgeClass) ?>">
                                <?= e($statusLabel) ?>
                            </span>

                            <div class="movie-overlay">
                                <span class="play-circle">
                                    ▶
                                </span>
                            </div>

                        </div>

                        <div class="movie-info">

                            <div class="movie-genre">
                                <?= e($movie['genre'] ?: 'Movie') ?>
                            </div>

                            <h3>
                                <?= e($movie['title']) ?>
                            </h3>

                            <div class="movie-meta">

                                <span>
                                    <?= (int)$movie['duration_min'] ?> min
                                </span>

                                <span>
                                    <?= e($movie['language']) ?>
                                </span>

                                <span class="rating">
                                    ★
                                    <?= number_format(
                                        (float)$movie['avg_rating'],
                                        1
                                    ) ?>
                                </span>

                            </div>

                        </div>

                    </a>

                <?php endwhile; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <strong>No movies found</strong>

                <p>
                    Try clearing the filters or using another keyword.
                </p>

                <a
                    class="btn btn-primary"
                    href="<?= url('movies.php') ?>"
                >
                    View all movies
                </a>
            </div>

        <?php endif; ?>

    </div>
</section>

<?php

$stmt->close();

require_once __DIR__ . '/includes/footer.php';

?>