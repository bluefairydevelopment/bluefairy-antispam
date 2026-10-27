<?php
/**
 * Site Health test: is the visitor's real IP address reaching the site?
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Site Health test: is the visitor's real IP address reaching the site?
 *
 * Behind Cloudflare, without the server restoring the visitor's address, every
 * visitor looks like one of Cloudflare's servers: the Stop Forum Spam check and
 * the block log see Cloudflare, and so does the rest of WordPress (comment IPs,
 * other plugins' limits). The plugin compensates for itself; the server fix
 * corrects it for everything.
 */
final class SiteHealth {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Add the test to Site Health.
	 *
	 * @param mixed $tests Site Health tests (an array, unless another filter broke it).
	 * @return array<string, mixed>
	 */
	public function register( mixed $tests ): array {
		$tests = is_array( $tests ) ? $tests : [];
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = [];
		}

		$tests['direct']['bfas_visitor_ip'] = [
			'label' => __( 'Visitor IP addresses', 'bluefairy-antispam' ),
			'test'  => [ $this, 'run' ],
		];

		return $tests;
	}

	/**
	 * Run the test against the current request.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$badge = [
			'label' => __( 'Security', 'bluefairy-antispam' ),
			'color' => 'blue',
		];

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( false !== filter_var( $remote, FILTER_VALIDATE_IP ) && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return [
				'label'       => __( 'Requests reach the site from a local or private address', 'bluefairy-antispam' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'This request arrived from a local or private network address. That is normal on a development site. On a live site it usually means a tunnel or proxy on the same network (such as Cloudflare Tunnel), and then every visitor appears to come from that one address: BlueFairy Anti-Spam\'s Stop Forum Spam check and block log, and WordPress itself, see the proxy instead of the visitor.', 'bluefairy-antispam' ) . '</p>'
					. '<p>' . esc_html__( 'The fix is on the server: have it restore the visitor\'s address from the proxy\'s header (Apache mod_remoteip, nginx real_ip), or use the bluefairy_antispam_client_ip filter.', 'bluefairy-antispam' ) . '</p>',
				'actions'     => '',
				'test'        => 'bfas_visitor_ip',
			];
		}

		if ( ! ClientIp::from_cloudflare( $_SERVER ) ) {
			return [
				'label'       => __( 'Visitor IP addresses reach the site', 'bluefairy-antispam' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'Requests do not arrive from Cloudflare\'s addresses, so BlueFairy Anti-Spam sees each visitor\'s own IP address.', 'bluefairy-antispam' ) . '</p>',
				'actions'     => '',
				'test'        => 'bfas_visitor_ip',
			];
		}

		$fix = '<p>' . esc_html__( 'Requests reach this site from Cloudflare\'s servers, and the web server is not restoring each visitor\'s own IP address. Until it does, WordPress records Cloudflare\'s address for every comment, and IP-based limits in other plugins treat all visitors as one.', 'bluefairy-antispam' ) . '</p>'
			. '<p>' . esc_html__( 'The best fix is on the server: Apache mod_remoteip, nginx real_ip, or LiteSpeed\'s "Use Client IP in Header" setting, each trusting Cloudflare\'s published address ranges. Your host can turn this on.', 'bluefairy-antispam' ) . '</p>';

		if ( (bool) $this->settings->get( 'cloudflare_detect', '1' ) ) {
			return [
				'label'       => __( 'BlueFairy Anti-Spam is working out visitor IP addresses behind Cloudflare', 'bluefairy-antispam' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => $fix . '<p>' . esc_html__( 'Meanwhile BlueFairy Anti-Spam compensates for itself: it uses the visitor address Cloudflare passes on, believed only from Cloudflare\'s own addresses.', 'bluefairy-antispam' ) . '</p>',
				'actions'     => '',
				'test'        => 'bfas_visitor_ip',
			];
		}

		return [
			'label'       => __( 'BlueFairy Anti-Spam sees Cloudflare\'s address instead of each visitor\'s', 'bluefairy-antispam' ),
			'status'      => 'critical',
			'badge'       => $badge,
			'description' => $fix . '<p>' . esc_html__( 'Auto-detect Cloudflare is turned off in BlueFairy Anti-Spam\'s settings, so its Stop Forum Spam check and block log see Cloudflare\'s address. Turn it on, or fix the server.', 'bluefairy-antispam' ) . '</p>',
			'actions'     => '',
			'test'        => 'bfas_visitor_ip',
		];
	}
}
