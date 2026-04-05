<?php
/**
 * Quote Club Portal Shortcode
 *
 * Registers [khm_quote_club_portal] which renders the dedicated sponsor-facing
 * Quote Club experience. Completely separate from [khm_member_portal]; uses
 * SponsorService for access gating and has its own navigation and layout.
 *
 * Navigation sections:
 *   overview       — credit balances, quick stats, purchase bundles
 *   commentary     — rolling-calendar search & commentary submission
 *   press-releases — press release composer (Phase 5)
 *   tracking       — article & PR performance dashboard (Phase 6)
 *   social         — LinkedIn & scheduling (Phase 7)
 *
 * @package KHM\PublicFrontend
 */

namespace KHM\PublicFrontend;

use KHM\Services\CreditService;
use KHM\Services\MembershipRepository;
use KHM\Services\LevelRepository;
use KHM\Services\SponsorService;
use KHM\Services\QuoteClubCreditBundleService;

defined( 'ABSPATH' ) || exit;

class QuoteClubPortalShortcode {

	private CreditService $credits;
	private QuoteClubCreditBundleService $bundles;

	public function __construct() {
		$memberships    = new MembershipRepository();
		$levels         = new LevelRepository();
		$this->credits  = new CreditService( $memberships, $levels );
		$this->bundles  = new QuoteClubCreditBundleService( $this->credits );
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function register(): void {
		add_shortcode( 'khm_quote_club_portal', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		global $post;

		if ( ! $post || ! has_shortcode( $post->post_content, 'khm_quote_club_portal' ) ) {
			return;
		}

		$plugin_url  = plugin_dir_url( dirname( __DIR__ ) );
		$plugin_path = plugin_dir_path( dirname( __DIR__ ) );

		wp_enqueue_style(
			'khm-quote-club-portal',
			$plugin_url . 'assets/css/quote-club-portal.css',
			[],
			file_exists( $plugin_path . 'assets/css/quote-club-portal.css' )
				? filemtime( $plugin_path . 'assets/css/quote-club-portal.css' )
				: '1'
		);

		// Reuse existing quote-club.css as base
		wp_enqueue_style(
			'khm-quote-club',
			$plugin_url . 'assets/css/quote-club.css',
			[ 'khm-quote-club-portal' ],
			file_exists( $plugin_path . 'assets/css/quote-club.css' )
				? filemtime( $plugin_path . 'assets/css/quote-club.css' )
				: '1'
		);

		wp_enqueue_script(
			'khm-quote-club',
			$plugin_url . 'assets/js/quote-club.js',
			[ 'jquery' ],
			file_exists( $plugin_path . 'assets/js/quote-club.js' )
				? filemtime( $plugin_path . 'assets/js/quote-club.js' )
				: '1',
			true
		);

		$user_id = get_current_user_id();
		$sponsor = SponsorService::get_user_sponsor( $user_id );

		wp_localize_script( 'khm-quote-club', 'khmQuoteClub', [
			'restUrl'       => esc_url_raw( rest_url( 'khm/v1/portal/quoteclub/' ) ),
			'sponsorRestUrl'=> esc_url_raw( rest_url( 'khm/v1/sponsor/' ) ),
			'bundleRestUrl' => esc_url_raw( rest_url( 'khm/v1/portal/quoteclub/bundles' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'userId'        => $user_id,
			'sponsorId'     => isset( $sponsor['id'] ) ? (int) $sponsor['id'] : 0,
			'editorialCredits' => $this->credits->getEditorialCredits( $user_id ),
			'pressReleaseCredits' => $this->credits->getPressReleaseCredits( $user_id ),
			'inviteToken'   => sanitize_text_field( (string) ( $_GET['khm_sponsor_invite'] ?? '' ) ),
			'inviteEmail'   => sanitize_email( (string) ( $_GET['khm_sponsor_invite_email'] ?? '' ) ),
			'wordsPerCredit'=> 120,
		] );
	}

	// -------------------------------------------------------------------------
	// Shortcode render
	// -------------------------------------------------------------------------

	public function render( array $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_required();
		}

		$user_id = get_current_user_id();
		$sponsor = SponsorService::get_user_sponsor( $user_id );

		if ( ! $sponsor && ! current_user_can( 'manage_options' ) ) {
			return $this->render_access_denied();
		}

		$section = isset( $_GET['qc_section'] )
			? sanitize_key( $_GET['qc_section'] )
			: 'overview';

		ob_start();
		?>
		<div class="khm-qc-portal" data-user-id="<?php echo esc_attr( $user_id ); ?>">

			<?php $this->render_header( $user_id, $sponsor ); ?>

			<div class="khm-qc-portal-body">
				<?php $this->render_nav( $section ); ?>

				<div class="khm-qc-portal-content">
					<?php
					switch ( $section ) {
						case 'commentary':
							$this->render_commentary_section( $user_id, $sponsor );
							break;
						case 'press-releases':
							$this->render_press_releases_section( $user_id, $sponsor );
							break;
						case 'tracking':
							$this->render_tracking_section( $user_id, $sponsor );
							break;
						case 'social':
							$this->render_social_section( $user_id, $sponsor );
							break;
						default:
							$this->render_overview_section( $user_id, $sponsor );
					}
					?>
				</div>
			</div>

			<div id="khm-qc-toast" class="khm-toast" role="status" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Header
	// -------------------------------------------------------------------------

	private function render_header( int $user_id, ?array $sponsor ): void {
		$user              = get_userdata( $user_id );
		$editorial_credits = $this->credits->getEditorialCredits( $user_id );
		$pr_credits        = $this->credits->getPressReleaseCredits( $user_id );
		$sponsor_name      = isset( $sponsor['name'] ) ? sanitize_text_field( $sponsor['name'] ) : '';
		?>
		<header class="khm-qc-header">
			<div class="khm-qc-header-identity">
				<div class="khm-qc-brand">
					<span class="khm-qc-brand-name">Quote Club</span>
					<?php if ( $sponsor_name ) : ?>
						<span class="khm-qc-sponsor-name"><?php echo esc_html( $sponsor_name ); ?></span>
					<?php endif; ?>
				</div>
				<div class="khm-qc-user-name">
					<?php echo esc_html( $user ? $user->display_name : '' ); ?>
				</div>
			</div>
			<div class="khm-qc-header-credits">
				<div class="khm-qc-credit-pill">
					<span class="khm-qc-credit-value" id="qc-editorial-balance"><?php echo (int) $editorial_credits; ?></span>
					<span class="khm-qc-credit-label"><?php esc_html_e( 'Editorial Credits', 'khm-membership' ); ?></span>
				</div>
				<div class="khm-qc-credit-pill">
					<span class="khm-qc-credit-value" id="qc-pr-balance"><?php echo (int) $pr_credits; ?></span>
					<span class="khm-qc-credit-label"><?php esc_html_e( 'Press Release Credits', 'khm-membership' ); ?></span>
				</div>
				<a href="<?php echo esc_url( add_query_arg( 'qc_section', 'overview' ) ); ?>#qc-bundles"
				   class="khm-qc-btn khm-qc-btn-sm"><?php esc_html_e( 'Buy Credits', 'khm-membership' ); ?></a>
			</div>
		</header>
		<?php
	}

	// -------------------------------------------------------------------------
	// Navigation
	// -------------------------------------------------------------------------

	private function render_nav( string $current_section ): void {
		$sections = [
			'overview'       => [ 'label' => __( 'Overview', 'khm-membership' ),       'icon' => 'dashicons-chart-bar' ],
			'commentary'     => [ 'label' => __( 'Commentary', 'khm-membership' ),      'icon' => 'dashicons-format-quote' ],
			'press-releases' => [ 'label' => __( 'Press Releases', 'khm-membership' ),  'icon' => 'dashicons-media-document' ],
			'tracking'       => [ 'label' => __( 'Tracking', 'khm-membership' ),        'icon' => 'dashicons-chart-line' ],
			'social'         => [ 'label' => __( 'Social', 'khm-membership' ),          'icon' => 'dashicons-share' ],
		];

		$sections = apply_filters( 'khm_qc_portal_sections', $sections );
		?>
		<nav class="khm-qc-nav" role="navigation" aria-label="<?php esc_attr_e( 'Quote Club sections', 'khm-membership' ); ?>">
			<?php foreach ( $sections as $slug => $section ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'qc_section', $slug ) ); ?>"
				   class="khm-qc-nav-item<?php echo $current_section === $slug ? ' is-active' : ''; ?>"
				   aria-current="<?php echo $current_section === $slug ? 'page' : 'false'; ?>">
					<span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
					<span><?php echo esc_html( $section['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sections
	// -------------------------------------------------------------------------

	private function render_overview_section( int $user_id, ?array $sponsor ): void {
		$editorial_credits = $this->credits->getEditorialCredits( $user_id );
		$pr_credits        = $this->credits->getPressReleaseCredits( $user_id );
		$active_bundles    = $this->bundles->list_bundles( true );
		?>
		<div class="khm-qc-section khm-qc-overview">
			<h2><?php esc_html_e( 'Overview', 'khm-membership' ); ?></h2>

			<div class="khm-qc-stats-grid">
				<div class="khm-qc-stat-card">
					<span class="khm-qc-stat-number"><?php echo (int) $editorial_credits; ?></span>
					<span class="khm-qc-stat-label"><?php esc_html_e( 'Editorial Credits Available', 'khm-membership' ); ?></span>
					<p class="khm-qc-stat-hint"><?php esc_html_e( '1 credit = 120 words of commentary', 'khm-membership' ); ?></p>
				</div>
				<div class="khm-qc-stat-card">
					<span class="khm-qc-stat-number"><?php echo (int) $pr_credits; ?></span>
					<span class="khm-qc-stat-label"><?php esc_html_e( 'Press Release Credits', 'khm-membership' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $active_bundles ) ) : ?>
			<div class="khm-qc-bundles" id="qc-bundles">
				<h3><?php esc_html_e( 'Purchase Credits', 'khm-membership' ); ?></h3>
				<div class="khm-qc-bundle-grid">
					<?php foreach ( $active_bundles as $bundle ) : ?>
					<div class="khm-qc-bundle-card">
						<h4><?php echo esc_html( $bundle->name ); ?></h4>
						<?php if ( $bundle->description ) : ?>
							<p><?php echo esc_html( $bundle->description ); ?></p>
						<?php endif; ?>
						<ul class="khm-qc-bundle-details">
							<?php if ( (int) $bundle->editorial_credits > 0 ) : ?>
								<li><?php echo (int) $bundle->editorial_credits; ?> <?php esc_html_e( 'Editorial Credits', 'khm-membership' ); ?></li>
							<?php endif; ?>
							<?php if ( (int) $bundle->press_release_credits > 0 ) : ?>
								<li><?php echo (int) $bundle->press_release_credits; ?> <?php esc_html_e( 'Press Release Credits', 'khm-membership' ); ?></li>
							<?php endif; ?>
						</ul>
						<div class="khm-qc-bundle-price">$<?php echo number_format( (int) $bundle->price_cents / 100, 2 ); ?></div>
						<button type="button"
						        class="khm-qc-btn khm-qc-btn-primary khm-qc-purchase-bundle"
						        data-bundle-id="<?php echo (int) $bundle->id; ?>"
						        data-bundle-name="<?php echo esc_attr( $bundle->name ); ?>">
							<?php esc_html_e( 'Buy Now', 'khm-membership' ); ?>
						</button>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="khm-qc-quick-links">
				<a href="<?php echo esc_url( add_query_arg( 'qc_section', 'commentary' ) ); ?>" class="khm-qc-btn khm-qc-btn-secondary">
					<?php esc_html_e( 'Search Articles & Submit Commentary →', 'khm-membership' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	private function render_commentary_section( int $user_id, ?array $sponsor ): void {
		?>
		<div class="khm-qc-section khm-qc-commentary">
			<!-- Invite / accept status banner -->
			<div class="khm-quoteclub-invite-status" role="status" aria-live="polite"></div>

			<h2><?php esc_html_e( 'Search Articles &amp; Submit Commentary', 'khm-membership' ); ?></h2>

			<div class="khm-quoteclub-toolbar">
				<input type="date" class="khm-filter-date-from" aria-label="<?php esc_attr_e( 'From date', 'khm-membership' ); ?>">
				<input type="date" class="khm-filter-date-to" aria-label="<?php esc_attr_e( 'To date', 'khm-membership' ); ?>">
				<input type="text" class="khm-filter-topics" placeholder="<?php esc_attr_e( 'Topics (comma-separated)', 'khm-membership' ); ?>">
				<input type="text" class="khm-filter-portfolio" placeholder="<?php esc_attr_e( 'Portfolio (comma-separated)', 'khm-membership' ); ?>">
				<input type="text" class="khm-filter-keywords" placeholder="<?php esc_attr_e( 'Keywords', 'khm-membership' ); ?>">
				<select class="khm-filter-operator" aria-label="<?php esc_attr_e( 'Keyword operator', 'khm-membership' ); ?>">
					<option value="AND"><?php esc_html_e( 'AND', 'khm-membership' ); ?></option>
					<option value="OR"><?php esc_html_e( 'OR', 'khm-membership' ); ?></option>
				</select>
				<select class="khm-saved-searches" aria-label="<?php esc_attr_e( 'Saved searches', 'khm-membership' ); ?>">
					<option value=""><?php esc_html_e( '— Saved Searches —', 'khm-membership' ); ?></option>
				</select>
				<button type="button" class="button khm-quoteclub-search-btn"><?php esc_html_e( 'Search', 'khm-membership' ); ?></button>
				<button type="button" class="button khm-save-search-btn"><?php esc_html_e( 'Save Search', 'khm-membership' ); ?></button>
			</div>

			<div class="khm-quoteclub-layout">
				<div class="khm-quoteclub-results" role="list" aria-label="<?php esc_attr_e( 'Search results', 'khm-membership' ); ?>"></div>
				<div class="khm-quoteclub-detail">
					<p class="khm-quoteclub-detail-placeholder">
						<?php esc_html_e( 'Select a result to view the brief and submit commentary.', 'khm-membership' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_press_releases_section( int $user_id, ?array $sponsor ): void {
		?>
		<div class="khm-qc-section khm-qc-press-releases">
			<h2><?php esc_html_e( 'Press Releases', 'khm-membership' ); ?></h2>
			<p class="khm-qc-coming-soon">
				<?php esc_html_e( 'The press release composer is coming soon. You will be able to draft, preview, and submit press releases for editorial approval from here.', 'khm-membership' ); ?>
			</p>
		</div>
		<?php
	}

	private function render_tracking_section( int $user_id, ?array $sponsor ): void {
		?>
		<div class="khm-qc-section khm-qc-tracking">
			<h2><?php esc_html_e( 'Tracking', 'khm-membership' ); ?></h2>
			<p class="khm-qc-coming-soon">
				<?php esc_html_e( 'Submission tracking and GEO / SEO performance metrics for your articles and press releases will appear here.', 'khm-membership' ); ?>
			</p>
		</div>
		<?php
	}

	private function render_social_section( int $user_id, ?array $sponsor ): void {
		?>
		<div class="khm-qc-section khm-qc-social">
			<h2><?php esc_html_e( 'Social Media', 'khm-membership' ); ?></h2>
			<p class="khm-qc-coming-soon">
				<?php esc_html_e( 'LinkedIn connection and scheduled post management will be available here.', 'khm-membership' ); ?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Access gate messages
	// -------------------------------------------------------------------------

	private function render_login_required(): string {
		$login_url = wp_login_url( get_permalink() );
		ob_start();
		?>
		<div class="khm-qc-access-gate">
			<h2><?php esc_html_e( 'Quote Club', 'khm-membership' ); ?></h2>
			<p><?php esc_html_e( 'Please log in to access the Quote Club sponsor portal.', 'khm-membership' ); ?></p>
			<a href="<?php echo esc_url( $login_url ); ?>" class="khm-qc-btn khm-qc-btn-primary"><?php esc_html_e( 'Log In', 'khm-membership' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_access_denied(): string {
		ob_start();
		?>
		<div class="khm-qc-access-gate">
			<h2><?php esc_html_e( 'Quote Club', 'khm-membership' ); ?></h2>
			<p><?php esc_html_e( 'This portal is available to Quote Club sponsor accounts. If you believe you should have access, please contact support.', 'khm-membership' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}
