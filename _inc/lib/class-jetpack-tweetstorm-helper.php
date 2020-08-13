<?php
/**
 * Tweetstorm block and API helper.
 *
 * @package jetpack
 * @since 8.7.0
 */

use Automattic\Jetpack\Connection\Client;
use Automattic\Jetpack\Status;
use Twitter\Text\Parser;

/**
 * Class Jetpack_Tweetstorm_Helper
 *
 * @since 8.7.0
 */
class Jetpack_Tweetstorm_Helper {
	/**
	 * Blocks that can be converted to tweets.
	 *
	 * @var array
	 */
	private static $supported_blocks = array(
		'core/heading'   => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
		),
		'core/list'      => array(
			'type'               => 'multiline',
			'content_attributes' => array( 'values' ),
			'template'           => '- {{line}}',
		),
		'core/paragraph' => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
		),
		'core/quote'     => array(
			'type'               => 'text',
			'content_attributes' => array( 'value', 'citation' ),
			'template'           => '“{{value}}” – {{citation}}',
		),
		'core/verse'     => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
		),
	);

	/**
	 * Gather the Tweetstorm.
	 *
	 * @param  string $url The tweet URL to gather from.
	 * @return mixed
	 */
	public static function gather( $url ) {
		if ( ( new Status() )->is_offline_mode() ) {
			return new WP_Error(
				'dev_mode',
				__( 'Tweet unrolling is not available in offline mode.', 'jetpack' )
			);
		}

		$site_id = self::get_site_id();
		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			if ( ! class_exists( 'WPCOM_Gather_Tweetstorm' ) ) {
				\jetpack_require_lib( 'gather-tweetstorm' );
			}

			return WPCOM_Gather_Tweetstorm::gather( $url );
		}

		$response = Client::wpcom_json_api_request_as_blog(
			sprintf( '/sites/%d/tweetstorm/gather?url=%s', $site_id, rawurlencode( $url ) ),
			2,
			array( 'headers' => array( 'content-type' => 'application/json' ) ),
			null,
			'wpcom'
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error( $data->code, $data->message, $data->data );
		}

		return $data;
	}

	/**
	 * Parse tweets.
	 *
	 * @param array $blocks An array of blocks that can be parsed into tweets.
	 * @param array $selected The currently selected blocks.
	 * @return mixed
	 */
	public static function parse( $blocks, $selected ) {
		$tweets = array();
		$parser = new Parser();

		foreach ( $blocks as $block ) {
			$block_text = self::extract_text_from_block( $block );
			$boundaries = array();

			$is_selected_block = count( $selected ) === 1 && $selected[0] === $block['clientId'];

			// Is this block too long for a single tweet?
			$tweet = $parser->parseTweet( $block_text );
			if ( $tweet->permillage > 1000 ) {
				// Split the block up by sentences.
				$sentences      = preg_split( '/([.!?]\s+)/', $block_text, -1, PREG_SPLIT_DELIM_CAPTURE );
				$sentence_count = count( $sentences );
				// An array of the tweets this block will become.
				$split_block = array( '' );
				// The tweet we're currently appending to.
				$current_block_tweet = 0;
				// Keep track of how many characters we've allocated to tweets so far.
				$current_character_count = 0;

				for ( $ii = 0; $ii < $sentence_count; $ii += 2 ) {
					$current_sentence = $sentences[ $ii ] . $sentences[ $ii + 1 ];

					// Is the current sentence too long for a single tweet?
					$tweet = $parser->parseTweet( trim( $current_sentence ) );
					if ( $tweet->permillage > 1000 ) {
						// This long sentence will start the next tweet this block becomes.
						if ( '' !== $split_block[ $current_block_tweet ] ) {
							$current_character_count += strlen( $split_block[ $current_block_tweet ] );
							$current_block_tweet++;
							$split_block[ $current_block_tweet ] = '';

							$boundaries[] = self::get_boundary( $block, $current_character_count );
						}

						// Split the long sentence into words.
						$words      = explode( ' ', $current_sentence );
						$word_count = count( $words );
						for ( $jj = 0; $jj < $word_count; $jj++ ) {
							// Will this word make the tweet too long?
							$tweet = $parser->parseTweet( trim( "…{$split_block[ $current_block_tweet ]} {$words[ $jj ]}…" ) );
							if ( $tweet->permillage > 1000 ) {
								// There's an extra space to count, hence the "+ 1".
								$current_character_count += strlen( $split_block[ $current_block_tweet ] ) + 1;
								$current_block_tweet++;
								$split_block[ $current_block_tweet ] = $words[ $jj ];

								// Offset one back for the extra space.
								$boundaries[] = self::get_boundary( $block, $current_character_count - 1 );
							} else {
								$split_block[ $current_block_tweet ] .= " {$words[ $jj ]}";
							}
						}
					} else {
						$tweet = $parser->parseTweet( $split_block[ $current_block_tweet ] . trim( $current_sentence ) );
						if ( $tweet->permillage > 1000 ) {
							// Appending this sentence will make the tweet too long, move to the next one.
							$current_character_count += strlen( $split_block[ $current_block_tweet ] );
							$current_block_tweet++;
							$split_block[ $current_block_tweet ] = $current_sentence;

							$boundaries[] = self::get_boundary( $block, $current_character_count );
						} else {
							$split_block[ $current_block_tweet ] .= $current_sentence;
						}
					}
				}

				$tweets[] = array(
					'blocks'     => array( $block ),
					'boundaries' => $boundaries,
					'current'    => $is_selected_block,
					'content'    => $block_text,
				);
				continue;
			}

			if ( empty( $tweets ) ) {
				$tweets[] = array(
					'blocks'     => array( $block ),
					'boundaries' => $boundaries,
					'current'    => $is_selected_block,
					'content'    => $block_text,
				);
				continue;
			}

			$last_tweet = array_pop( $tweets );

			$last_tweet_text = array_reduce(
				$last_tweet['blocks'],
				function( $generated_tweet, $allocated_block ) {
					if ( ! $generated_tweet ) {
						return self::extract_text_from_block( $allocated_block );
					}

					return "$generated_tweet\n\n" . self::extract_text_from_block( $allocated_block );
				},
				false
			);

			$new_tweet_text = "$last_tweet_text\n\n$block_text";
			$tweet          = $parser->parseTweet( $new_tweet_text );
			if ( $tweet->permillage > 1000 ) {
				$tweets[] = $last_tweet;
				$tweets[] = array(
					'blocks'     => array( $block ),
					'boundaries' => $boundaries,
					'current'    => $is_selected_block,
					'content'    => $block_text,
				);
				continue;
			}

			if ( ( ! $last_tweet['current'] ) && $is_selected_block ) {
				$last_tweet['current'] = $is_selected_block;
			}

			$last_tweet['blocks'][] = $block;
			$last_tweet['content']  = $new_tweet_text;
			$tweets[]               = $last_tweet;
		}

		return $tweets;
	}

	/**
	 * Given a block, and an offset of how many characters into the tweet that block generates,
	 * this function calculates which attribute area (in the block editor, the richTextIdentifier)
	 * that offset corresponds to, and how far into that attribute area it is.
	 *
	 * @param array   $block        The block being checked.
	 * @param integer $total_offset The offset for the tweet this block generates.
	 * @return array
	 */
	private static function get_boundary( $block, $total_offset ) {
		$template_parts = preg_split( '/({{\w+}})/', self::$supported_blocks[ $block['name'] ]['template'], -1, PREG_SPLIT_DELIM_CAPTURE );

		$current_character_count = 0;

		foreach ( $template_parts as $part ) {
			$matches = array();
			if ( preg_match( '/{{(\w+)}}/', $part, $matches ) ) {
				$attribute_name   = $matches[1];
				$attribute_length = strlen( $block['attributes'][ $attribute_name ] );
				if ( $current_character_count + $attribute_length >= $total_offset ) {
					$attribute_offset = $total_offset - $current_character_count;
					return array(
						'start'     => $attribute_offset - 1,
						'end'       => $attribute_offset,
						'container' => $attribute_name,
					);
				} else {
					$current_character_count += $attribute_length;
					continue;
				}
			} else {
				$current_character_count += strlen( $part );
			}
		}
	}

	/**
	 * Extracts the tweetable text from a block.
	 *
	 * @param array $block The block, as represented in the block editor.
	 */
	private static function extract_text_from_block( $block ) {
		if ( empty( self::$supported_blocks[ $block['name'] ] ) ) {
			return '';
		}

		$block_def = self::$supported_blocks[ $block['name'] ];

		if ( 'text' === $block_def['type'] ) {
			$text = array_reduce(
				$block_def['content_attributes'],
				function( $current_text, $attribute ) use ( $block ) {
					return str_replace( '{{' . $attribute . '}}', $block['attributes'][ $attribute ], $current_text );
				},
				$block_def['template']
			);
		} elseif ( 'multiline' === $block_def['type'] ) {
			$text = array_reduce(
				$block['splitAttributes'][ $block_def['content_attributes'][0] ],
				function( $current_text, $line ) use ( $block_def ) {
					if ( ! empty( $current_text ) ) {
						$current_text .= "\n";
					}
					return $current_text . str_replace( '{{line}}', $line, $block_def['template'] );
				},
				''
			);
		}

		return wp_strip_all_tags( $text );
	}

	/**
	 * Get the WPCOM or self-hosted site ID.
	 *
	 * @return mixed
	 */
	public static function get_site_id() {
		$is_wpcom = ( defined( 'IS_WPCOM' ) && IS_WPCOM );
		$site_id  = $is_wpcom ? get_current_blog_id() : Jetpack_Options::get_option( 'id' );
		if ( ! $site_id ) {
			return new WP_Error(
				'unavailable_site_id',
				__( 'Sorry, something is wrong with your Jetpack connection.', 'jetpack' ),
				403
			);
		}
		return (int) $site_id;
	}
}
