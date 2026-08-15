<?php
require_once __DIR__ . '/includes/config.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT m.*,COALESCE(AVG(r.stars),0) avg_rating,COUNT(r.id) review_count FROM movies m LEFT JOIN reviews r ON r.movie_id=m.id AND r.is_approved=1 WHERE m.id=? GROUP BY m.id");
$stmt->bind_param('i',$id); $stmt->execute(); $movie=$stmt->get_result()->fetch_assoc();
if(!$movie){ flash('error','Movie not found.'); redirect('movies.php'); }
$pageTitle = $movie['title'];

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['review_submit'])){
    requireLogin(); verifyCsrf();
    $stars=max(1,min(5,(int)($_POST['stars']??5)));
    $title=trim((string)($_POST['title']??''));
    $review=trim((string)($_POST['review']??''));
    if($review===''){ flash('error','Please write a review.'); redirect('movie.php?id='.$id); }
    $uid=(int)$_SESSION['user_id'];
    $up=$db->prepare("INSERT INTO reviews(movie_id,user_id,stars,title,review,is_approved) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE stars=VALUES(stars),title=VALUES(title),review=VALUES(review),created_at=CURRENT_TIMESTAMP");
    $up->bind_param('iiiss',$id,$uid,$stars,$title,$review); $up->execute();
    flash('success','Your review has been saved.'); redirect('movie.php?id='.$id.'#reviews');
}

$showStmt=$db->prepare("SELECT s.*,c.name cinema_name,c.city,c.address FROM showtimes s JOIN cinemas c ON c.id=s.cinema_id WHERE s.movie_id=? AND s.is_active=1 AND c.is_active=1 AND (s.show_date>CURDATE() OR (s.show_date=CURDATE() AND s.show_time>=CURTIME())) ORDER BY s.show_date,s.show_time");
$showStmt->bind_param('i',$id); $showStmt->execute(); $shows=$showStmt->get_result();
$grouped=[]; while($show=$shows->fetch_assoc()){ $grouped[$show['show_date']][$show['cinema_id']][]=$show; }
$reviewStmt=$db->prepare("SELECT r.*,u.username,u.full_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.movie_id=? AND r.is_approved=1 ORDER BY r.created_at DESC");
$reviewStmt->bind_param('i',$id); $reviewStmt->execute(); $reviews=$reviewStmt->get_result();
$embed=youtubeEmbed($movie['trailer_url']);
$genreKey = strtolower((string)$movie['genre']);
$movieAccent = '#f20f2f';
foreach ([
    'sci' => '#4f8cff', 'fantasy' => '#7659ff', 'horror' => '#b641ff',
    'romance' => '#ff5d99', 'animation' => '#ffb24d', 'comedy' => '#ffd15d',
    'action' => '#ff384c', 'thriller' => '#ef3354', 'drama' => '#d88c5a'
] as $needle => $color) {
    if (str_contains($genreKey, $needle)) { $movieAccent = $color; break; }
}
$castMembers = array_values(array_filter(array_map('trim', preg_split('/[,|]/', (string)($movie['cast_list'] ?? '')) ?: [])));
require_once __DIR__ . '/includes/header.php';
?>
<section class="movie-detail" data-movie-accent="<?= e($movieAccent) ?>" style="--movie-accent:<?= e($movieAccent) ?>">
    <section class="movie-detail-hero">
        <div class="movie-detail-backdrop" style="background-image:url('<?= posterUrl($movie['poster']) ?>')" aria-hidden="true"></div>
        <div class="container">
            <a class="helper reveal-cinema" href="<?= url('movies.php') ?>">← Back to all movies</a>
            <div class="detail-grid">
                <div class="detail-poster reveal-cinema" data-tilt-strength="7">
                    <img src="<?= posterUrl($movie['poster']) ?>" alt="<?= e($movie['title']) ?> poster" fetchpriority="high">
                </div>
                <div class="detail-info">
                    <div class="tag-row reveal-cinema">
                        <span class="tag tag-red"><?= e($movie['genre']) ?></span>
                        <span class="tag <?= $movie['status']==='now_showing'?'tag-green':'' ?>"><?= e(str_replace('_',' ',$movie['status'])) ?></span>
                    </div>
                    <h1><?= e($movie['title']) ?></h1>
                    <div class="detail-meta reveal-cinema">
                        <span>★ <?= number_format((float)$movie['avg_rating'],1) ?> · <?= (int)$movie['review_count'] ?> reviews</span>
                        <span><?= (int)$movie['duration_min'] ?> min</span>
                        <span><?= e($movie['language']) ?></span>
                        <span><?= e($movie['age_rating']) ?></span>
                    </div>
                    <p class="detail-description reveal-cinema"><?= nl2br(e($movie['description'])) ?></p>
                    <div class="detail-facts reveal-cinema">
                        <div class="fact"><span>Director</span><strong><?= e($movie['director'] ?: 'Not announced') ?></strong></div>
                        <div class="fact"><span>Release date</span><strong><?= $movie['release_date']?date('d M Y',strtotime($movie['release_date'])):'TBA' ?></strong></div>
                        <div class="fact"><span>Audience score</span><strong><?= number_format((float)$movie['avg_rating'],1) ?>/5</strong></div>
                    </div>
                    <?php if($movie['status']==='now_showing' && $grouped): ?>
                        <a class="btn btn-primary magnetic reveal-cinema" href="#showtimes">Select a Showtime</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="container">
        <?php if ($castMembers || !empty($movie['director'])): ?>
        <section class="section" style="padding-bottom:34px">
            <div class="section-head reveal-cinema">
                <div><div class="section-tag">Cast & creative team</div><h2 class="section-title">Behind the <span class="accent">story</span></h2></div>
            </div>
            <div class="cast-grid">
                <?php if (!empty($movie['director'])):
                    $directorInitials = implode('', array_map(static fn($part) => strtoupper(substr($part,0,1)), array_slice(preg_split('/\s+/', trim($movie['director'])) ?: [], 0, 2)));
                ?>
                    <article class="cast-card" data-initials="<?= e($directorInitials) ?>"><span>Director</span><strong><?= e($movie['director']) ?></strong></article>
                <?php endif; ?>
                <?php foreach (array_slice($castMembers, 0, 8) as $member):
                    $initials = implode('', array_map(static fn($part) => strtoupper(substr($part,0,1)), array_slice(preg_split('/\s+/', trim($member)) ?: [], 0, 2)));
                ?>
                    <article class="cast-card" data-initials="<?= e($initials) ?>"><span>Cast</span><strong><?= e($member) ?></strong></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <div class="content-grid">
            <section class="panel reveal-cinema" id="showtimes">
                <div class="section-tag">Choose your screening</div>
                <h2>Showtimes</h2>
                <?php if(!$grouped): ?>
                    <div class="empty-state"><strong>No active shows</strong>New dates will be announced soon.</div>
                <?php else: ?>
                    <?php foreach($grouped as $date=>$cinemas): ?>
                        <h3 style="margin-top:28px"><?= e(formatShowDate($date)) ?></h3>
                        <?php foreach($cinemas as $cinemaShows): $first=$cinemaShows[0]; ?>
                            <div class="cinema-block">
                                <div class="cinema-head"><div><strong><?= e($first['cinema_name']) ?></strong><p><?= e($first['city']) ?> · <?= e($first['address']) ?></p></div></div>
                                <div class="time-list">
                                    <?php foreach($cinemaShows as $s): ?>
                                        <a class="time-btn magnetic" href="<?= url('booking.php?showtime_id='.(int)$s['id']) ?>"><?= date('h:i A',strtotime($s['show_time'])) ?> · from <?= money($s['gold_price']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <aside class="panel reveal-cinema" id="trailer">
                <div class="section-tag">Official preview</div>
                <h3>Trailer</h3>
                <?php if($embed): ?>
                    <iframe class="trailer" src="<?= e($embed) ?>" title="<?= e($movie['title']) ?> trailer" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                <?php else: ?>
                    <div class="empty-state">Trailer is not available.</div>
                <?php endif; ?>
                <h3 style="margin-top:28px">Seat Classes</h3>
                <div class="summary-line"><span>Gold</span><strong>Standard comfort</strong></div>
                <div class="summary-line"><span>Platinum</span><strong>Premium view</strong></div>
                <div class="summary-line"><span>Box</span><strong>Luxury seating</strong></div>
            </aside>
        </div>

        <section class="panel reveal-cinema" id="reviews" style="margin-top:24px">
            <div class="section-head"><div><div class="section-tag">Audience feedback</div><h2 class="section-title" style="font-size:38px">Reviews & Ratings</h2></div></div>
            <?php if(isLoggedIn()): ?>
                <form method="post" style="margin-bottom:22px"><?= csrfField() ?><input type="hidden" name="review_submit" value="1">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Rating</label><select class="form-control" name="stars"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> star<?= $i>1?'s':'' ?></option><?php endfor; ?></select></div>
                        <div class="form-group"><label class="form-label">Review title</label><input class="form-control" name="title" maxlength="150" placeholder="Worth watching"></div>
                    </div>
                    <div class="form-group"><label class="form-label">Your review</label><textarea class="form-control" name="review" required></textarea></div>
                    <button class="btn btn-primary magnetic">Submit Review</button>
                </form>
            <?php else: ?><p class="section-copy"><a class="accent" href="<?= url('login.php') ?>">Log in</a> to write a review.</p><?php endif; ?>

            <div class="review-timeline">
            <?php if($reviews->num_rows): while($review=$reviews->fetch_assoc()): ?>
                <article class="review">
                    <div class="review-head"><div><strong><?= e($review['full_name'] ?: $review['username']) ?></strong><div class="helper"><?= date('d M Y',strtotime($review['created_at'])) ?></div></div><div class="stars"><?= str_repeat('★',(int)$review['stars']).str_repeat('☆',5-(int)$review['stars']) ?></div></div>
                    <?php if($review['title']): ?><h4><?= e($review['title']) ?></h4><?php endif; ?><p><?= nl2br(e($review['review'])) ?></p>
                </article>
            <?php endwhile; else: ?><div class="empty-state">No reviews yet. Be the first to share your opinion.</div><?php endif; ?>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
