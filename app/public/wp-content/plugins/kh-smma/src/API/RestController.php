<?php
namespace KH_SMMA\API;

use KH_SMMA\Services\FeatureFlags;
use KH_SMMA\Services\SmmaGenerator;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Services\PhaseEngine;
use KH_SMMA\Services\ScheduleQueueProcessor;
use KH_SMMA\Services\ComplianceValidator;
use KH_SMMA\Compliance\SuggestedEditService;
use KH_SMMA\Admin\VariantGridComplianceRenderer;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestController {
    private FeatureFlags $flags;
    private SmmaGenerator $generator;
    private AuditLogger $logger;
    private ?PhaseEngine $phase_engine;
    private ?ComplianceValidator $compliance_validator;
    private SuggestedEditService $suggested_edit_service;
    private VariantGridComplianceRenderer $compliance_renderer;

    public function __construct( FeatureFlags $flags, SmmaGenerator $generator, AuditLogger $logger, PhaseEngine $phase_engine = null, ComplianceValidator $compliance_validator = null, SuggestedEditService $suggested_edit_service = null, VariantGridComplianceRenderer $compliance_renderer = null ) {
        $this->flags = $flags;
        $this->generator = $generator;
        $this->logger = $logger;
        $this->phase_engine = $phase_engine;
        $this->compliance_validator = $compliance_validator ?? new ComplianceValidator();
        $this->suggested_edit_service = $suggested_edit_service ?? new SuggestedEditService();
        $this->compliance_renderer = $compliance_renderer ?? new VariantGridComplianceRenderer();
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route( 'kh-smma/v1', '/generate', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_generate' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/export', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_export' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/record-event', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_record_event' ),
            'permission_callback' => array( $this, 'check_record_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/schedule', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_schedule' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/boost/prepare', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_boost_prepare' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/approve', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_approve' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/sponsor/(?P<id>\\d+)', array(
            'methods' => 'GET',
            'callback' => array( $this, 'handle_get_sponsor' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/seo-table', array(
            'methods' => 'GET',
            'callback' => array( $this, 'handle_seo_table' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/variant-edit', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_variant_edit' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/variant/request-approval', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_variant_request_approval' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( 'kh-smma/v1', '/reject', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_reject' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );
    }

    public function check_permissions( WP_REST_Request $request ) {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'kh_smma_forbidden', __( 'Insufficient permissions.', 'kh-smma' ), array( 'status' => 403 ) );
        }

        if ( ! $this->flags->is_enabled( 'smma' ) ) {
            return new WP_Error( 'kh_smma_disabled', __( 'SMMA feature is disabled by feature flag.', 'kh-smma' ), array( 'status' => 403 ) );
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'kh_smma_bad_nonce', __( 'Invalid REST nonce.', 'kh-smma' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function check_record_permissions( WP_REST_Request $request ) {
        if ( is_user_logged_in() ) {
            $nonce = $request->get_header( 'X-WP-Nonce' );
            if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return true;
            }
        }

        $service_token = get_option( 'kh_smma_service_token' );
        if ( $service_token ) {
            $header_token = $request->get_header( 'x-kh-service-token' );
            if ( ! $header_token ) {
                $header_token = $request->get_header( 'x-service-token' );
            }
            if ( $header_token && hash_equals( $service_token, $header_token ) ) {
                return true;
            }
        }

        return new WP_Error( 'kh_smma_forbidden', __( 'Invalid permissions.', 'kh-smma' ), array( 'status' => 403 ) );
    }

    public function handle_generate( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( empty( $payload['post_id'] ) ) {
            return new WP_Error( 'kh_smma_missing_post', __( 'post_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $phase_context = $payload['phase_context'] ?? array();
        if ( empty( $phase_context ) ) {
            $phase_context = $this->resolve_phase_context( $payload );
        }

        $phase_tag = $payload['phase_tag'] ?? '';
        if ( empty( $phase_tag ) && ! empty( $phase_context['assigned_phase'] ) ) {
            $phase_tag = $phase_context['assigned_phase'];
        }
        if ( empty( $phase_tag ) ) {
            $phase_tag = 'Attention';
        }

        // Log generation request
        $prompt_hash = hash( 'sha256', wp_json_encode( array(
            'post_id'   => (int) $payload['post_id'],
            'phase_tag' => $phase_tag,
            'tone'      => $payload['tone'] ?? 'Authority',
        ) ) );

        $this->logger->log_generate_request(
            (int) $payload['post_id'],
            get_current_user_id(),
            $prompt_hash,
            array(
                'num_variants'        => $payload['num_variants'] ?? 1,
                'tone'                => $payload['tone'] ?? 'Authority',
                'geo_targets'         => $payload['geo_targets'] ?? array(),
                'generate_google_ads' => $payload['generate_google_ads'] ?? true,
            )
        );

        $result = $this->generator->generate( array(
            'post_id'             => (int) $payload['post_id'],
            'blocks_json'         => $payload['blocks_json'] ?? array(),
            'phase_tag'           => $phase_tag,
            'num_variants'        => $payload['num_variants'] ?? 1,
            'series'              => (bool) ( $payload['series'] ?? false ),
            'tone'                => $payload['tone'] ?? 'Authority',
            'geo_targets'         => $payload['geo_targets'] ?? array(),
            'sponsor_context'     => $payload['sponsor_context'] ?? array(),
            'user_controls'       => $payload['user_controls'] ?? array(),
            'keywords'            => $payload['keywords'] ?? array(),
            'intent_scores'       => $payload['intent_scores'] ?? array(),
            'audience_presets'    => $payload['audience_presets'] ?? array(),
            'phase_context'       => $phase_context,
            'user_id'             => (int) ( $payload['user_id'] ?? get_current_user_id() ),
            'generate_google_ads' => $payload['generate_google_ads'] ?? true,
            'num_ad_groups'       => $payload['num_ad_groups'] ?? 2,
            'title'               => $payload['title'] ?? '',
            'canonical_url'       => $payload['canonical_url'] ?? '',
            'blocks_summary'      => $payload['blocks_summary'] ?? '',
        ) );

        $this->logger->log( 'smma_generate', array(
            'object_type' => 'post',
            'object_id' => (int) $payload['post_id'],
            'details' => array(
                'variants' => is_array( $result['variants'] ) ? count( $result['variants'] ) : 0,
                'phase_tag' => $phase_tag,
                'tone' => $payload['tone'] ?? 'Authority',
            ),
            'user_id' => get_current_user_id(),
        ) );

        ScheduleQueueProcessor::log_telemetry( (int) $payload['post_id'], array(
            'mode' => 'generate',
            'provider' => 'smma',
            'request' => array(
                'post_id' => (int) $payload['post_id'],
                'variants' => $payload['num_variants'] ?? 1,
                'phase_tag' => $phase_tag,
            ),
        ) );

        // Validate Google Ads draft if present
        $google_ad_compliance = array();
        if ( ! empty( $result['google_ad_draft'] ) && ! empty( $result['google_ad_draft']['ad_groups'] ) ) {
            $google_ad_compliance = $this->compliance_validator->validate_google_ad_draft(
                $result['google_ad_draft'],
                array(
                    'sponsor_id'      => $payload['sponsor_context']['sponsor_id'] ?? null,
                    'allowed_claims'  => $payload['sponsor_context']['allowed_claims'] ?? array(),
                )
            );
        }

        // Log generation response
        $variant_ids = array();
        if ( ! empty( $result['linkedin_variants'] ) ) {
            foreach ( $result['linkedin_variants'] as $variant ) {
                if ( ! empty( $variant['variant_id'] ) ) {
                    $variant_ids[] = $variant['variant_id'];
                }
            }
        }

        $response_hash = hash( 'sha256', wp_json_encode( $result ) );
        $this->logger->log_generate_response(
            $response_hash,
            $variant_ids,
            array(
                'model'            => $result['model'] ?? 'unknown',
                'google_ad_draft'  => ! empty( $result['google_ad_draft'] ),
            )
        );

        $linkedin_variants = $result['linkedin_variants'] ?? array();
        foreach ( $linkedin_variants as $index => $variant ) {
            $notes = (string) ( $variant['compliance_notes'] ?? '' );
            $status = $this->derive_compliance_status( $notes );
            $rules_triggered = $this->suggested_edit_service->infer_rules_from_compliance_notes( $notes );
            $suggested_edits = array();

            if ( in_array( $status, array( 'WARN', 'FAIL' ), true ) ) {
                $suggested_edits = $this->suggested_edit_service->generate_for_text(
                    (string) ( $variant['text'] ?? '' ),
                    $rules_triggered
                );

                do_action( 'kh_smma_telemetry_event', 'compliance.suggested_edit_generated', array(
                    'trace_id'   => uniqid( 'com-', true ),
                    'variant_id' => (string) ( $variant['variant_id'] ?? '' ),
                    'rule_id'    => implode( ',', $rules_triggered ),
                    'timestamp'  => time(),
                ) );
            }

            $linkedin_variants[ $index ]['compliance_status'] = $status;
            $linkedin_variants[ $index ]['rationale'] = $notes ?: 'No compliance issues detected.';
            $linkedin_variants[ $index ]['rules_triggered'] = $rules_triggered;
            $linkedin_variants[ $index ]['suggested_edits'] = $suggested_edits;
            $linkedin_variants[ $index ]['compliance_ui'] = $this->compliance_renderer->build_payload( $linkedin_variants[ $index ] );

            do_action( 'kh_smma_telemetry_event', 'variant.compliance.viewed', array(
                'trace_id'   => uniqid( 'com-', true ),
                'variant_id' => (string) ( $variant['variant_id'] ?? '' ),
                'status'     => $status,
                'timestamp'  => time(),
            ) );
        }

        return rest_ensure_response( array(
            'variants'                => $linkedin_variants,
            'linkedin_variants'       => $linkedin_variants,
            'google_ad_draft'         => $result['google_ad_draft'] ?? array(),
            'google_ad_compliance'    => $google_ad_compliance,
            'model'                   => $result['model'] ?? 'unknown',
        ) );
    }

    public function handle_record_event( WP_REST_Request $request ) {
        if ( ! $this->phase_engine ) {
            return new WP_Error( 'kh_smma_phase_engine_missing', __( 'Phase Engine unavailable.', 'kh-smma' ), array( 'status' => 500 ) );
        }

        $payload  = $request->get_json_params();
        $event_id = sanitize_text_field( $payload['event_id'] ?? '' );
        $metadata = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();

        if ( empty( $event_id ) ) {
            return new WP_Error( 'kh_smma_missing_event', __( 'event_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            $user_id = absint( $payload['user_id'] ?? 0 );
        }

        if ( ! $user_id ) {
            return new WP_Error( 'kh_smma_missing_user', __( 'user_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $result = $this->phase_engine->record_event( (int) $user_id, $event_id, 'smma_rest', (array) $metadata );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'event_id' => $event_id,
            'user_id' => (int) $user_id,
        ) );
    }

    private function resolve_phase_context( array $payload ): array {
        if ( ! $this->phase_engine ) {
            return array();
        }

        $user_id = (int) ( $payload['user_id'] ?? get_current_user_id() );
        if ( ! $user_id ) {
            return array();
        }

        $context = $this->phase_engine->get_user_phase( $user_id );
        return is_array( $context ) ? $context : array();
    }

    public function handle_schedule( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $post_id = (int) ( $payload['post_id'] ?? 0 );
        $schedule = $payload['schedule'] ?? array();

        if ( ! $post_id || empty( $schedule ) || ! is_array( $schedule ) ) {
            return new WP_Error( 'kh_smma_invalid_schedule', __( 'post_id and schedule are required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $boost = ! empty( $payload['boost'] );
        $boost_settings = $payload['boost_settings'] ?? array();
        $sponsor_context = $payload['sponsor_context'] ?? array();

        // Determine if approval is required based on sponsor or boost settings
        $approval_required = false;
        if ( ! empty( $sponsor_context['approval_required'] ) ) {
            $approval_required = true;
        } elseif ( $boost && ! empty( $boost_settings['requires_approval'] ) ) {
            $approval_required = true;
        }

        $created = array();
        foreach ( $schedule as $item ) {
            // Timezone handling: Convert ISO 8601 datetime to UTC timestamp
            $scheduled_at_input = $item['scheduled_at'] ?? time();
            $scheduled_at_utc = $this->parse_datetime_to_utc( $scheduled_at_input );
            $original_timezone = $this->extract_timezone( $scheduled_at_input );

            $variant_id   = sanitize_text_field( $item['variant_id'] ?? '' );
            $geo          = sanitize_text_field( $item['geo'] ?? '' );
            $variant_text = $item['text'] ?? '';
            $item_compliance_status = strtoupper( sanitize_text_field( $item['compliance_status'] ?? 'OK' ) );
            $approval_record = $this->get_variant_approval_request( $variant_id );

            if ( 'FAIL' === $item_compliance_status ) {
                return new WP_Error( 'kh_smma_compliance_fail', __( 'Scheduling blocked due to compliance violation.', 'kh-smma' ), array(
                    'status' => 409,
                    'compliance_status' => 'FAIL',
                ) );
            }

            if ( 'WARN' === $item_compliance_status ) {
                $record_status = (string) ( $approval_record['approval_status'] ?? '' );
                $item_status = (string) ( $item['approval_status'] ?? '' );
                if ( 'approved' !== $record_status && 'approved' !== $item_status ) {
                    return new WP_Error( 'kh_smma_approval_required', __( 'Variant requires sponsor approval before scheduling.', 'kh-smma' ), array(
                        'status' => 409,
                        'compliance_status' => 'WARN',
                        'approval_status' => $record_status ?: 'pending',
                    ) );
                }
            }

            $item_approval_required = $approval_required || 'WARN' === $item_compliance_status;
            $item_approval_status = $item_approval_required ? ( ( 'approved' === (string) ( $approval_record['approval_status'] ?? '' ) || 'approved' === (string) ( $item['approval_status'] ?? '' ) ) ? 'approved' : 'pending' ) : 'auto_approved';
            $item_schedule_status = ( $item_approval_required && 'approved' !== $item_approval_status ) ? 'awaiting_approval' : 'pending';

            $variant_payload = array(
                'post_id' => $post_id,
                'variant_id' => $variant_id,
                'channel' => 'linkedin',
                'geo' => $geo,
                'text' => $variant_text,
                'compliance_status' => $item_compliance_status,
                'meta' => array(
                    'source' => 'smma_rest',
                    'generated_by' => 'smma-ai-v1',
                    'original_timezone' => $original_timezone,
                    'scheduled_at_input' => $scheduled_at_input,
                ),
            );

            $schedule_id = wp_insert_post( array(
                'post_type' => 'kh_smma_schedule',
                'post_title' => sprintf( __( 'SMMA Schedule – %d', 'kh-smma' ), $post_id ),
                'post_status' => 'publish',
            ), true );

            if ( is_wp_error( $schedule_id ) ) {
                return $schedule_id;
            }

            update_post_meta( $schedule_id, '_kh_smma_payload', $variant_payload );
            update_post_meta( $schedule_id, '_kh_smma_scheduled_at', $scheduled_at_utc );
            update_post_meta( $schedule_id, '_kh_smma_original_timezone', $original_timezone );
            update_post_meta( $schedule_id, '_kh_smma_schedule_status', $item_schedule_status );
            update_post_meta( $schedule_id, '_kh_smma_delivery_mode', 'manual_export' );
            update_post_meta( $schedule_id, '_kh_smma_sponsor_id', isset( $sponsor_context['sponsor_id'] ) ? (int) $sponsor_context['sponsor_id'] : 0 );
            update_post_meta( $schedule_id, '_kh_smma_sponsor_mode', sanitize_text_field( $sponsor_context['policy'] ?? '' ) );
            update_post_meta( $schedule_id, '_kh_smma_sponsor_assets', $sponsor_context['sponsor_assets'] ?? array() );
            update_post_meta( $schedule_id, '_kh_smma_boost_mode', $boost ? 'linkedin' : 'none' );
            update_post_meta( $schedule_id, '_kh_smma_boost_settings', $boost_settings );
            update_post_meta( $schedule_id, '_kh_smma_approval_status', $item_approval_status );
            update_post_meta( $schedule_id, '_kh_smma_approval_required', $item_approval_required );

            if ( ! $item_approval_required || 'approved' === $item_approval_status ) {
                update_post_meta( $schedule_id, '_kh_smma_approved_by', 'system' );
                update_post_meta( $schedule_id, '_kh_smma_approved_at', time() );
            }

            ScheduleQueueProcessor::log_telemetry( $schedule_id, array(
                'mode' => 'schedule',
                'provider' => 'smma',
                'payload_preview' => $variant_payload,
            ) );

            $created[] = array(
                'schedule_id' => $schedule_id,
                'schedule_status' => $item_schedule_status,
                'approval_status' => $item_approval_status,
                'approval_required' => $item_approval_required,
            );
        }

        return rest_ensure_response( array(
            'created' => $created,
        ) );
    }

    public function handle_boost_prepare( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $schedule_id = (int) ( $payload['schedule_id'] ?? 0 );

        if ( ! $schedule_id ) {
            return new WP_Error( 'kh_smma_missing_schedule', __( 'schedule_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $schedule_payload = get_post_meta( $schedule_id, '_kh_smma_payload', true );
        $export_bundle = array(
            'schedule_id' => $schedule_id,
            'account_id' => 0,
            'payload' => $schedule_payload,
            'generated' => time(),
        );

        update_post_meta( $schedule_id, '_kh_smma_export_bundle', $export_bundle );
        update_post_meta( $schedule_id, '_kh_smma_schedule_status', 'awaiting_manual_export' );

        return rest_ensure_response( array(
            'status' => 'awaiting_manual_export',
            'bundle' => $export_bundle,
        ) );
    }

    public function handle_approve( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $schedule_id = (int) ( $payload['schedule_id'] ?? 0 );
        $approver_id = (int) ( $payload['approver_id'] ?? get_current_user_id() );
        $notes = sanitize_textarea_field( $payload['notes'] ?? '' );

        if ( ! $schedule_id ) {
            return new WP_Error( 'kh_smma_missing_schedule', __( 'schedule_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        // Authorization: Check capability for sponsor approvals
        if ( ! current_user_can( 'approve_sponsor_posts' ) && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'kh_smma_insufficient_permissions',
                __( 'You do not have permission to approve sponsored content.', 'kh-smma' ),
                array( 'status' => 403 )
            );
        }

        // Idempotency: Check if already approved
        $current_status = get_post_meta( $schedule_id, '_kh_smma_approval_status', true );
        if ( 'approved' === $current_status ) {
            $approved_by = get_post_meta( $schedule_id, '_kh_smma_approved_by', true );
            $approved_at = get_post_meta( $schedule_id, '_kh_smma_approved_at', true );

            return rest_ensure_response( array(
                'status' => 'approved',
                'message' => __( 'This schedule was already approved.', 'kh-smma' ),
                'approved_by' => $approved_by,
                'approved_at' => $approved_at,
                'idempotent' => true,
            ) );
        }

        // Update approval status
        update_post_meta( $schedule_id, '_kh_smma_approval_status', 'approved' );
        update_post_meta( $schedule_id, '_kh_smma_approved_by', $approver_id );
        update_post_meta( $schedule_id, '_kh_smma_approved_at', time() );
        update_post_meta( $schedule_id, '_kh_smma_schedule_status', 'pending' );
        if ( $notes ) {
            update_post_meta( $schedule_id, '_kh_smma_approval_notes', $notes );
        }

        // Audit logging
        $this->logger->log( 'smma_schedule_approve', array(
            'object_type' => 'schedule',
            'object_id' => $schedule_id,
            'details' => array(
                'approver_id' => $approver_id,
                'notes' => $notes,
            ),
            'user_id' => get_current_user_id(),
        ) );

        // Telemetry
        ScheduleQueueProcessor::log_telemetry( $schedule_id, array(
            'mode' => 'approve',
            'provider' => 'smma',
            'approver_id' => $approver_id,
            'notes' => $notes,
        ) );

        return rest_ensure_response( array(
            'status' => 'approved',
            'schedule_id' => $schedule_id,
            'approved_by' => $approver_id,
            'approved_at' => time(),
        ) );
    }

    public function handle_get_sponsor( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! $id ) {
            return new WP_Error( 'kh_smma_missing_sponsor', __( 'Sponsor ID is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        if ( ! function_exists( 'kh_ad_manager_get_sponsor_meta' ) ) {
            return new WP_Error( 'kh_smma_sponsor_missing', __( 'Sponsorship Manager not available.', 'kh-smma' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response( kh_ad_manager_get_sponsor_meta( $id ) );
    }

    public function handle_seo_table( WP_REST_Request $request ) {
        $posts = get_posts( array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ) );

        $rows = array();
        foreach ( $posts as $post ) {
            $rows[] = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'seo_score' => (int) get_post_meta( $post->ID, '_khm_seo_score', true ),
                'geo_score' => (int) get_post_meta( $post->ID, '_khm_geo_score', true ),
                'sponsor' => '',
                'promote' => true,
                'boost' => true,
            );
        }

        return rest_ensure_response( array( 'rows' => $rows ) );
    }

    public function handle_variant_edit( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $schedule_id = (int) ( $payload['schedule_id'] ?? 0 );
        $variant_id = sanitize_text_field( $payload['variant_id'] ?? '' );
        $updated_text = (string) ( $payload['updated_text'] ?? '' );

        if ( ! $schedule_id && '' === $variant_id ) {
            return new WP_Error( 'kh_smma_invalid_edit', __( 'schedule_id or variant_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        if ( '' === $updated_text ) {
            return new WP_Error( 'kh_smma_invalid_edit', __( 'updated_text is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        do_action( 'kh_smma_telemetry_event', 'variant.compliance.edit_requested', array(
            'trace_id'   => uniqid( 'com-', true ),
            'variant_id' => $variant_id,
            'status'     => 'requested',
            'timestamp'  => time(),
        ) );

        $schedule_payload = array();
        if ( $schedule_id > 0 ) {
            $schedule_payload = get_post_meta( $schedule_id, '_kh_smma_payload', true );
            if ( empty( $schedule_payload ) ) {
                return new WP_Error( 'kh_smma_schedule_not_found', __( 'Schedule not found.', 'kh-smma' ), array( 'status' => 404 ) );
            }
        } else {
            $schedule_payload = array(
                'variant_id' => $variant_id,
                'text'       => (string) ( $payload['current_text'] ?? '' ),
                'channel'    => 'linkedin',
                'phase_tag'  => sanitize_text_field( $payload['phase_tag'] ?? 'Attention' ),
            );
        }

        // Store original text for diff calculation
        $original_text = $schedule_payload['text'] ?? '';

        $sponsor_id = (int) get_post_meta( $schedule_id, '_kh_smma_sponsor_id', true );
        $sponsor_context = array(
            'sponsor_id' => $sponsor_id,
            'sponsor_policy' => get_post_meta( $schedule_id, '_kh_smma_sponsor_mode', true ),
            'sponsor_assets' => get_post_meta( $schedule_id, '_kh_smma_sponsor_assets', true ),
            'channel' => $schedule_payload['channel'] ?? 'linkedin',
            'phase_tag' => $schedule_payload['phase_tag'] ?? 'Attention',
        );

        // Get allowed claims for sponsor if applicable
        if ( $sponsor_id && function_exists( 'kh_ad_manager_get_sponsor_meta' ) ) {
            $sponsor_meta = kh_ad_manager_get_sponsor_meta( $sponsor_id );
            $sponsor_context['allowed_claims'] = $sponsor_meta['allowed_claims'] ?? array();
        }

        if ( ! empty( $payload['apply_all_suggested_fixes'] ) ) {
            $pre_rules = $this->suggested_edit_service->get_known_rule_ids();
            $pre_suggestions = $this->suggested_edit_service->generate_for_text( $updated_text, $pre_rules );
            $updated_text = $this->suggested_edit_service->apply_suggestions( $updated_text, $pre_suggestions );
        } elseif ( ! empty( $payload['suggested_edits'] ) && is_array( $payload['suggested_edits'] ) ) {
            $updated_text = $this->suggested_edit_service->apply_suggestions( $updated_text, $payload['suggested_edits'] );
        }

        $compliance_check = $this->compliance_validator->validate( $updated_text, $sponsor_context );
        $compliance_status = $this->derive_compliance_status( (string) ( $compliance_check['notes'] ?? '' ), (bool) ( $compliance_check['passed'] ?? false ) );
        $rules_triggered = $this->derive_rules_from_compliance_result( $compliance_check );
        $suggested_edits = $this->suggested_edit_service->generate_for_text( $updated_text, $rules_triggered );

        if ( ! empty( $suggested_edits ) ) {
            do_action( 'kh_smma_telemetry_event', 'compliance.suggested_edit_generated', array(
                'trace_id'   => uniqid( 'com-', true ),
                'variant_id' => (string) ( $schedule_payload['variant_id'] ?? '' ),
                'rule_id'    => implode( ',', $rules_triggered ),
                'timestamp'  => time(),
            ) );
        }

        if ( ! $compliance_check['passed'] ) {
            $this->logger->record_event( 'compliance.check', array(
                'user_id'     => get_current_user_id(),
                'object_type' => $schedule_id > 0 ? 'schedule' : 'variant',
                'object_id'   => $schedule_id,
                'status'      => $compliance_status,
                'rule_id'     => $rules_triggered,
                'timestamp'   => current_time( 'mysql' ),
            ) );
            return new WP_Error( 'kh_smma_compliance_failed', $compliance_check['message'], array(
                'status' => 422,
                'compliance' => array(
                    'status'          => $compliance_status,
                    'rationale'       => $compliance_check['message'] ?? ( $compliance_check['notes'] ?? '' ),
                    'rules_triggered' => $rules_triggered,
                    'suggested_edits' => $suggested_edits,
                    'compliance_ui'   => $this->compliance_renderer->build_payload( array(
                        'compliance_status' => $compliance_status,
                        'rationale' => $compliance_check['message'] ?? ( $compliance_check['notes'] ?? '' ),
                        'rules_triggered' => $rules_triggered,
                    ) ),
                ),
            ) );
        }

        // Calculate unified diff
        $unified_diff = $this->calculate_unified_diff( $original_text, $updated_text );

        $schedule_payload['text'] = sanitize_textarea_field( $updated_text );
        $schedule_payload['edited_at'] = time();
        $schedule_payload['edited_by'] = get_current_user_id();

        if ( $schedule_id > 0 ) {
            update_post_meta( $schedule_id, '_kh_smma_payload', $schedule_payload );
            update_post_meta( $schedule_id, '_kh_smma_compliance_notes', $compliance_check['notes'] );
        }

        // Store preview changes with full metadata
        $preview_changes = array(
            'variant_id' => $schedule_payload['variant_id'] ?? '',
            'editor_id' => get_current_user_id(),
            'full_text' => $updated_text,
            'unified_diff' => $unified_diff,
            'timestamp' => time(),
            'compliance_result' => array_merge( $compliance_check, array(
                'status' => $compliance_status,
                'rules_triggered' => $rules_triggered,
                'suggested_edits' => $suggested_edits,
            ) ),
        );
        if ( $schedule_id > 0 ) {
            update_post_meta( $schedule_id, '_kh_smma_preview_changes', $preview_changes );
        }

        $this->logger->log( 'smma_variant_edit', array(
            'object_type' => $schedule_id > 0 ? 'schedule' : 'variant',
            'object_id' => $schedule_id,
            'details' => array(
                'compliance_passed' => $compliance_check['passed'],
                'edited_by' => get_current_user_id(),
                'diff_size' => strlen( $unified_diff ),
            ),
            'user_id' => get_current_user_id(),
        ) );
        $this->logger->record_event( 'compliance.check', array(
            'user_id'     => get_current_user_id(),
            'object_type' => $schedule_id > 0 ? 'schedule' : 'variant',
            'object_id'   => $schedule_id,
            'status'      => $compliance_status,
            'rule_id'     => $rules_triggered,
            'timestamp'   => current_time( 'mysql' ),
        ) );

        // Enhanced telemetry with diff
        if ( $schedule_id > 0 ) {
            ScheduleQueueProcessor::log_telemetry( $schedule_id, array(
                'mode' => 'variant_edit',
                'provider' => 'smma',
                'editor_id' => get_current_user_id(),
                'diff' => $unified_diff,
                'compliance_result' => $compliance_check,
            ) );
        }

        if ( 'suggested_edit' === (string) ( $payload['source'] ?? '' ) ) {
            $rule_ids = is_array( $payload['rule_ids'] ?? null ) ? $payload['rule_ids'] : array();
            foreach ( $rule_ids as $rule_id ) {
                do_action( 'kh_smma_telemetry_event', 'compliance.suggested_edit_applied', array(
                    'trace_id'   => uniqid( 'com-', true ),
                    'variant_id' => (string) ( $schedule_payload['variant_id'] ?? '' ),
                    'rule_id'    => sanitize_text_field( (string) $rule_id ),
                    'timestamp'  => time(),
                ) );
            }

            $this->logger->record_event( 'variant.edit', array(
                'user_id'     => get_current_user_id(),
                'object_type' => 'schedule',
                'object_id'   => $schedule_id,
                'source'      => 'suggested_edit',
                'rule_id'     => $rule_ids,
                'timestamp'   => current_time( 'mysql' ),
            ) );
        }

        return rest_ensure_response( array(
            'status' => 'updated',
            'compliance' => array_merge( $compliance_check, array(
                'status'          => $compliance_status,
                'rationale'       => $compliance_check['message'] ?? ( $compliance_check['notes'] ?? '' ),
                'rules_triggered' => $rules_triggered,
                'suggested_edits' => $suggested_edits,
                'compliance_ui'   => $this->compliance_renderer->build_payload( array(
                    'compliance_status' => $compliance_status,
                    'rationale' => $compliance_check['message'] ?? ( $compliance_check['notes'] ?? '' ),
                    'rules_triggered' => $rules_triggered,
                ) ),
            ) ),
            'updated_text' => $updated_text,
        ) );
    }

    public function handle_variant_request_approval( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $variant_id = sanitize_text_field( $payload['variant_id'] ?? '' );
        $status = strtoupper( sanitize_text_field( $payload['compliance_status'] ?? 'WARN' ) );
        $reason = sanitize_textarea_field( $payload['rationale'] ?? '' );

        if ( '' === $variant_id ) {
            return new WP_Error( 'kh_smma_invalid_variant', __( 'variant_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        if ( 'WARN' !== $status ) {
            return new WP_Error( 'kh_smma_invalid_status', __( 'Only WARN variants can request approval.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $record = array(
            'variant_id'        => $variant_id,
            'approval_required' => true,
            'approval_status'   => 'pending',
            'requested_by'      => get_current_user_id(),
            'requested_at'      => time(),
            'rationale'         => $reason,
        );
        $this->set_variant_approval_request( $variant_id, $record );

        do_action( 'kh_smma_telemetry_event', 'sponsor.approval.requested', array(
            'trace_id'   => uniqid( 'com-', true ),
            'variant_id' => $variant_id,
            'status'     => 'pending',
            'timestamp'  => time(),
        ) );
        do_action( 'kh_smma_telemetry_event', 'variant.compliance.request_approval', array(
            'trace_id'   => uniqid( 'com-', true ),
            'variant_id' => $variant_id,
            'status'     => 'WARN',
            'timestamp'  => time(),
        ) );

        $this->logger->record_event( 'approval.requested', array(
            'user_id'     => get_current_user_id(),
            'object_type' => 'variant',
            'object_id'   => 0,
            'variant_id'  => $variant_id,
            'status'      => 'pending',
            'reason'      => $reason,
            'timestamp'   => current_time( 'mysql' ),
        ) );

        return rest_ensure_response( array(
            'status' => 'pending_approval',
            'variant_id' => $variant_id,
            'approval_required' => true,
            'approval_status' => 'pending',
        ) );
    }

    public function handle_reject( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        $schedule_id = (int) ( $payload['schedule_id'] ?? 0 );
        $reason = sanitize_textarea_field( $payload['reason'] ?? '' );

        if ( ! $schedule_id ) {
            return new WP_Error( 'kh_smma_missing_schedule', __( 'schedule_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        // Authorization: Check capability for sponsor approvals/rejections
        if ( ! current_user_can( 'approve_sponsor_posts' ) && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'kh_smma_insufficient_permissions',
                __( 'You do not have permission to reject sponsored content.', 'kh-smma' ),
                array( 'status' => 403 )
            );
        }

        // Idempotency: Check if already rejected
        $current_status = get_post_meta( $schedule_id, '_kh_smma_approval_status', true );
        if ( 'rejected' === $current_status ) {
            $rejected_by = get_post_meta( $schedule_id, '_kh_smma_rejected_by', true );
            $rejected_at = get_post_meta( $schedule_id, '_kh_smma_rejected_at', true );
            $existing_reason = get_post_meta( $schedule_id, '_kh_smma_rejection_reason', true );

            return rest_ensure_response( array(
                'status' => 'rejected',
                'message' => __( 'This schedule was already rejected.', 'kh-smma' ),
                'rejected_by' => $rejected_by,
                'rejected_at' => $rejected_at,
                'reason' => $existing_reason,
                'idempotent' => true,
            ) );
        }

        update_post_meta( $schedule_id, '_kh_smma_approval_status', 'rejected' );
        update_post_meta( $schedule_id, '_kh_smma_rejected_by', get_current_user_id() );
        update_post_meta( $schedule_id, '_kh_smma_rejected_at', time() );
        update_post_meta( $schedule_id, '_kh_smma_rejection_reason', $reason );
        update_post_meta( $schedule_id, '_kh_smma_schedule_status', 'rejected' );

        $this->logger->log( 'smma_schedule_reject', array(
            'object_type' => 'schedule',
            'object_id' => $schedule_id,
            'details' => array(
                'reason' => $reason,
                'rejected_by' => get_current_user_id(),
            ),
            'user_id' => get_current_user_id(),
        ) );

        ScheduleQueueProcessor::log_telemetry( $schedule_id, array(
            'mode' => 'reject',
            'provider' => 'smma',
            'rejected_by' => get_current_user_id(),
            'rejection_reason' => $reason,
        ) );

        return rest_ensure_response( array(
            'status' => 'rejected',
            'schedule_id' => $schedule_id,
            'rejected_by' => get_current_user_id(),
            'rejected_at' => time(),
        ) );
    }

    private function derive_compliance_status( string $notes, bool $passed = true ): string {
        $normalized = strtolower( $notes );
        if ( ! $passed ) {
            if ( false !== strpos( $normalized, 'warn' ) ) {
                return 'WARN';
            }
            return 'FAIL';
        }

        if ( false !== strpos( $normalized, 'warn' ) ) {
            return 'WARN';
        }
        if ( false !== strpos( $normalized, 'fail' ) ) {
            return 'FAIL';
        }

        return 'OK';
    }

    private function derive_rules_from_compliance_result( array $compliance_result ): array {
        $rules = array();
        if ( ! empty( $compliance_result['violation_type'] ) ) {
            $rules[] = 'missing_claim' === $compliance_result['violation_type'] ? 'unverified_performance' : 'banned_phrase_guarantee';
        }

        $notes = (string) ( $compliance_result['notes'] ?? '' );
        $message = (string) ( $compliance_result['message'] ?? '' );
        $rules = array_merge(
            $rules,
            $this->suggested_edit_service->infer_rules_from_compliance_notes( $notes . ' ' . $message )
        );

        return array_values( array_unique( $rules ) );
    }

    private function get_variant_approval_request( string $variant_id ): array {
        $all = get_option( 'kh_smma_variant_approval_requests', array() );
        if ( ! is_array( $all ) ) {
            return array();
        }

        $row = $all[ $variant_id ] ?? array();
        return is_array( $row ) ? $row : array();
    }

    private function set_variant_approval_request( string $variant_id, array $record ): void {
        $all = get_option( 'kh_smma_variant_approval_requests', array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }
        $all[ $variant_id ] = $record;
        update_option( 'kh_smma_variant_approval_requests', $all );
    }

    /**
     * Parse datetime input (ISO 8601 or unix timestamp) to UTC timestamp.
     *
     * @param mixed $input Datetime input (ISO 8601 string or unix timestamp)
     * @return int UTC unix timestamp
     */
    private function parse_datetime_to_utc( $input ): int {
        // If already a unix timestamp, return as-is
        if ( is_int( $input ) || ( is_string( $input ) && ctype_digit( $input ) ) ) {
            return (int) $input;
        }

        // Parse ISO 8601 datetime string
        if ( is_string( $input ) ) {
            try {
                $datetime = new \DateTime( $input );
                // Convert to UTC
                $datetime->setTimezone( new \DateTimeZone( 'UTC' ) );
                return $datetime->getTimestamp();
            } catch ( \Exception $e ) {
                // Fallback to current time on parse error
                return time();
            }
        }

        return time();
    }

    /**
     * Extract timezone from ISO 8601 datetime string.
     *
     * @param mixed $input Datetime input
     * @return string Timezone identifier (e.g., "America/New_York", "-05:00", "UTC")
     */
    private function extract_timezone( $input ): string {
        if ( ! is_string( $input ) ) {
            return 'UTC';
        }

        try {
            $datetime = new \DateTime( $input );
            $timezone = $datetime->getTimezone();
            return $timezone ? $timezone->getName() : 'UTC';
        } catch ( \Exception $e ) {
            return 'UTC';
        }
    }

    /**
     * Calculate unified diff between original and updated text.
     *
     * @param string $original Original text
     * @param string $updated Updated text
     * @return string Unified diff string
     */
    private function calculate_unified_diff( string $original, string $updated ): string {
        if ( $original === $updated ) {
            return '';
        }

        $original_lines = explode( "\n", $original );
        $updated_lines = explode( "\n", $updated );

        $diff = array();
        $diff[] = '--- Original';
        $diff[] = '+++ Updated';
        $diff[] = '@@ -1,' . count( $original_lines ) . ' +1,' . count( $updated_lines ) . ' @@';

        // Simple line-by-line diff
        $max_lines = max( count( $original_lines ), count( $updated_lines ) );
        for ( $i = 0; $i < $max_lines; $i++ ) {
            $orig_line = $original_lines[ $i ] ?? null;
            $upd_line = $updated_lines[ $i ] ?? null;

            if ( $orig_line === null && $upd_line !== null ) {
                $diff[] = '+' . $upd_line;
            } elseif ( $orig_line !== null && $upd_line === null ) {
                $diff[] = '-' . $orig_line;
            } elseif ( $orig_line !== $upd_line ) {
                $diff[] = '-' . $orig_line;
                $diff[] = '+' . $upd_line;
            } else {
                $diff[] = ' ' . $orig_line;
            }
        }

        return implode( "\n", $diff );
    }

    /**
     * Handle export request for manual paid campaign export
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_export( WP_REST_Request $request ) {
        $payload = $request->get_json_params();

        if ( empty( $payload['post_id'] ) ) {
            return new WP_Error( 'kh_smma_missing_post', __( 'post_id is required.', 'kh-smma' ), array( 'status' => 400 ) );
        }

        $post_id              = (int) $payload['post_id'];
        $variant_ids          = $payload['variant_ids'] ?? array();
        $include_google_ads   = $payload['include_google_ads'] ?? true;
        $include_assets       = $payload['include_assets'] ?? true;

        // Create export directory
        $upload_dir = wp_upload_dir();
        $export_dir = trailingslashit( $upload_dir['basedir'] ) . 'smma-exports';
        if ( ! file_exists( $export_dir ) ) {
            wp_mkdir_p( $export_dir );
        }

        $export_id = 'exp_' . $post_id . '_' . time();
        $export_path = trailingslashit( $export_dir ) . $export_id;
        wp_mkdir_p( $export_path );

        // Gather variant data
        $variants = array();
        foreach ( $variant_ids as $variant_id ) {
            $variant = get_transient( 'kh_smma_variant_' . $variant_id );
            if ( $variant ) {
                $variants[] = $variant;
            }
        }

        // Gather Google Ads draft if requested
        $google_ad_draft = array();
        if ( $include_google_ads ) {
            $google_ad_draft = get_transient( 'kh_smma_google_ads_' . $post_id );
        }

        // Build manifest
        $manifest = array(
            'export_id'         => $export_id,
            'post_id'           => $post_id,
            'created_at'        => current_time( 'mysql' ),
            'expires_at'        => gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '+7 days' ) ),
            'linkedin_variants' => $variants,
            'google_ad_draft'   => $google_ad_draft,
            'assets'            => array(),
        );

        // Save manifest JSON
        $manifest_file = $export_path . '/manifest.json';
        file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

        // Create ZIP archive
        $zip_file = $export_dir . '/' . $export_id . '.zip';
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new \ZipArchive();
            if ( $zip->open( $zip_file, \ZipArchive::CREATE ) === true ) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $export_path ),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ( $files as $file ) {
                    if ( ! $file->isDir() ) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr( $file_path, strlen( $export_path ) + 1 );
                        $zip->addFile( $file_path, $relative_path );
                    }
                }
                $zip->close();
            }
        }

        // Generate download URL
        $download_url = trailingslashit( $upload_dir['baseurl'] ) . 'smma-exports/' . $export_id . '.zip';

        // Log export
        $this->logger->log( 'export.create', array(
            'object_type' => 'export',
            'object_id'   => $post_id,
            'details'     => array(
                'export_id'           => $export_id,
                'variant_count'       => count( $variants ),
                'include_google_ads'  => $include_google_ads,
                'include_assets'      => $include_assets,
            ),
        ) );

        return rest_ensure_response( array(
            'export_id'    => $export_id,
            'download_url' => $download_url,
            'manifest'     => $manifest,
            'expires_at'   => gmdate( 'Y-m-d\\TH:i:s\\Z', strtotime( '+7 days' ) ),
        ) );
    }

}
