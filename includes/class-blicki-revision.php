<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blicki suggestion handling.
 */
class Blicki_Suggestion {

    /**
     * Post ID.
     * @var integer
     */
    private $entry_id = 0;

    /**
     * Suggestion ID.
     * @var integer
     */
    private $suggestion_id = 0;

    /**
     * Construct.
     */
    public function __construct() {
        add_action( 'post_submitbox_start', array( $this, 'pending_suggestion_button' ) );
        add_filter( 'wp_insert_post_data' , array( $this, 'maybe_create_suggestion' ), 20, 2 );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
    }

    /**
     * True if we're submitting changes, not an update.
     * @param int $post_id
     * @return boolean
     */
    public function is_suggesting_changes( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }
        if ( ! $post = get_post( $post_id ) ) {
            return false;
        }
        if ( 'blicki' !== $post->post_type ) {
            return false;
        }
        if ( 'publish' !== $post->post_status ) {
            return false;
        }
        if ( empty( $_POST['blicki-suggest-changes'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Allow a post to be saved as a suggestion rather than updated right away.
     */
    public function pending_suggestion_button() {
        global $post;

        if ( 'blicki' !== $post->post_type ) {
            return;
        }

        echo '<div style="margin-bottom: 12px;"><input style="display:block; width:100%;" name="blicki-suggest-changes" type="submit" class="button button-large" value="' . esc_html__( 'Suggest changes', 'blicki' ) . '"></div>';
    }

    /**
     * Create a suggestion for this wiki entry if needed.
     * @param array $data
     * @param array $postarr
     */
    public function maybe_create_suggestion( $data, $postarr ) {
        $post_id = ! empty( $postarr['ID'] ) ? $postarr['ID'] : 0;

        if ( $post_id && $this->is_suggesting_changes( $post_id ) ) {
            $old_post_data = get_post( $post_id );

            // If content has changed, create a suggestion and prevent content from updating.
            if ( $old_post_data->post_content !== $data['post_content'] || $old_post_data->post_title !== $data['post_title'] ) {
                // Create suggestion.
                $this->entry_id    = $post_id;
                $this->suggestion_id = $this->create_suggestion( $post_id, $data );

                // Remove content update.
                $data['post_content'] = $old_post_data->post_content;
                $data['post_title']   = $old_post_data->post_title;

                // Make sure we redirect to the diff view after save.
                add_filter( 'redirect_post_location', array( $this, 'redirect_to_diff' ) );
            }
        }

        return $data;
    }

    /**
     * Create a suggestion for this wiki entry.
     * @param int $post_id
     * @param array $data
     */
    public static function create_suggestion( $post_id, $data ) {
        $post_id = (int) wp_insert_post( array(
            'post_type'    => 'blicki-suggestion',
			'post_name'    => md5( $data['post_content'] ),
            'post_title'   => $data['post_title'],
            'post_content' => $data['post_content'],
            'post_parent'  => $post_id,
            'post_status'  => 'pending',
            'post_author'  => get_current_user_id(),
        ) );
		update_post_meta( $data, '_blicki_author_email', isset( $data['author_email'] ) ? $data['author_email'] : '' );
		update_post_meta( $data, '_blicki_author_name', isset( $data['author_name'] ) ? $data['author_name'] : '' );
		return $post_id;
    }

    /**
     * Redirect to diff.
     * @param  string $url
     * @return string
     */
    public function redirect_to_diff( $url ) {
        return $this->get_diff_viewer_url( $this->entry_id, $this->suggestion_id );
    }

    /**
     * Add meta boxes.
     */
    public function add_meta_boxes() {
        add_meta_box( 'blick-suggestions', __( 'Blicki Suggestions', 'blicki' ), array( $this, 'blick_suggestions_content' ), 'blicki', 'side', 'high' );
    }

    /**
     * Show suggestions when editing a wiki entry.
     */
    public function blick_suggestions_content() {
        global $post;

		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require( ABSPATH . WPINC . '/wp-diff.php' );
		}

        $post_id   = $post->ID;
        $suggestions = $this->get_suggestions_for_entry( $post_id );

        if ( $suggestions ) {
            echo '<ul class="blicki-suggestion-list">';
            foreach ( $suggestions as $suggestion_id ) {
                $suggestion = get_post( $suggestion_id );
                $date     = date_i18n( get_option( 'date_format' ), strtotime( $suggestion->post_date ) );

                if ( $suggestion->post_author ) {
                    $user     = get_user_by( 'id', $suggestion->post_author );
                    $username = $user->display_name;
                } else {
                    $username = '';
                }

				$text_diff = new Text_Diff( explode( "\n", $post->post_content ), explode( "\n", $suggestion->post_content ) );

                echo
					'<li class="blicki-suggestion-list-item">',
					sprintf( esc_html_x( 'Suggestion by %s on %s', 'Suggestion by user on date', 'blicki' ), '<strong>' . $username . '</strong>', $date ),
					'<br/><a href="' . esc_url( $this->get_diff_viewer_url( $post_id, $suggestion_id ) ) . '" title="' . esc_html__( 'Show diff', 'blicki' ) . '">',
					sprintf( esc_html_x( '%d changes', 'X changes', 'blicki' ), sizeof( $text_diff->_edits ) ),
					'</a>',
					'</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Get URL to diff view.
     * @param  int $source_id
     * @param  int $suggestion_id
     * @return string
     */
    public static function get_diff_viewer_url( $source_id, $suggestion_id ) {
        return add_query_arg( array( 'page' => 'blicki-show-diff', 'suggestion' => absint( $suggestion_id ), 'source' => absint( $source_id ) ), admin_url( 'edit.php?post_type=blicki' ) );
    }

    /**
     * Get IDs of suggestions for an entry.
     * @param  int $id
     * @return int[]
     */
	public static function get_suggestions_for_entry( $id, $status = 'any' ) {
        return get_posts( array(
            'fields'         => 'ids',
			'post_type'      => 'blicki-suggestion',
            'post_parent'    => $id,
            'posts_per_page' => -1,
            'post_status'    => $status,
        ) );
    }

	/**
	 * Sort by count.
	 */
	private static function sort_by_count( $a, $b ) {
		if ( $a->count === $b->count ) {
        	return 0;
    	}
    	return ( $a->count < $b->count ) ? -1 : 1;
	}

	/**
	 * Get a list of contributors to a wiki entry.
	 * @param  int $entry_id
	 * @return array
	 */
	public static function get_contributors_for_entry( $entry_id ) {
		$contributors = array();
		$suggestions  = self::get_suggestions_for_entry( $entry_id );

		foreach ( $suggestions as $suggestion_id ) {
			$suggestion = get_post( $suggestion_id );

			if ( $suggestion->post_author ) {
				if ( isset( $contributors[ $suggestion->post_author ] ) ) {
					$contributors[ $suggestion->post_author ]->count ++;
				} else {
					$user = get_user_by( 'id', $suggestion->post_author );
					$contributors[ $suggestion->post_author ] = (object) array(
						'email' => $user->user_email,
						'name'  => $user->display_name,
						'count' => 1
					);
				}
			} else {
				$email = get_post_meta( $suggestion_id, '_blicki_author_email', true );
				$name  = get_post_meta( $suggestion_id, '_blicki_author_name', true );
				
				if ( isset( $contributors[ $email ] ) ) {
					$contributors[ $email ]->count ++;
				} else {
					$contributors[ $email ] = (object) array(
						'email' => $email,
						'name'  => $name,
						'count' => 1
					);
				}
			}
		}
		uasort( $contributors, array( __CLASS__, 'sort_by_count' ) );
		return array_reverse( $contributors );
	}
}
new Blicki_Suggestion();