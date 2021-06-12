<?php

class WPGraphQLUnitTestCaseTest extends \Tests\WPGraphQL\TestCase\WPGraphQLUnitTestCase {
	
	public function tearDown(): void {
		parent::tearDown();
		WPGraphQL::clear_schema();
	}

	/** @test */
	public function test_AssertQuerySuccessful() {
		// Create posts for later use.
		$post_id          = self::factory()->post->create();
		$unneeded_post_id = self::factory()->post->create();

		// GraphQL query and variables.
		$query     = '
			query ($id: ID!) {
				post( id: $id ) {
					id
					databaseId
				}
				posts {
					nodes {
						id
					}
				}
			}
		';
		$variables = array(
			'id' => $this->toRelayId( 'post', $post_id ),
		);

		// Execute query and get response.
		$response = $this->graphql( compact( 'query', 'variables' ) );

		// Expected data.
		$expected = array(
			$this->expectedObject( 'post.id', null ), // If null provided, field existence is asserted.
			$this->not()->expectedObject( 'post.id', 'null' ), // If "null" provided, asserts if field value is NULL.
			$this->expectedObject( 'post.id', $this->toRelayId( 'post', $post_id ) ),
			$this->expectedObject( 'post.databaseId', $post_id ),
			$this->not()->expectedObject( 'post.databaseId', 10001 ),
			$this->expectedNode(
				'posts.nodes',
				array( 'id' => $this->toRelayId( 'post', $post_id ) )
			),
			$this->not()->expectedNode(
				'posts.nodes',
				array( 'id' => $this->toRelayId( 'post', 10001 ) )
			)
		);

		// Assert query successful.
		$this->assertQuerySuccessful( $response, $expected );
	}

	public function test_AssertQueryError() {
		register_graphql_object_type(
			'FailingType',
			array(
				'fields' => array(
					'try' => array(
						'type'    => 'String',
						'args'    => array(
							'fail' => array(
								'type' => 'Boolean'
							)
						),
						'resolve' => function( $_, $args ) {
							if ( ! empty( $args['fail'] ) && $args['fail'] ) {
								throw new \GraphQL\Error\UserError( 'testErrorQuery worked as expected' );
							}
							
							return 'No fails here';
						}
					),
					'trying' => array(
						'type'    => array( 'list_of' => 'String' ),
						'args'    => array(
							'fail' => array(
								'type' => 'Boolean'
							)
						),
						'resolve' => function( $_, $args ) {                            
							return ! empty( $args['fail'] ) && $args['fail']
								? absint(1.1)
								: [ 'No', 'fails', 'here', 'either' ];
						}
					)
				),
			)
		);

		register_graphql_field(
			'RootQuery',
			'testFailingType',
			array(
				'type'    => 'FailingType',
				'resolve' => function() {
					return [];
				},
			)
		);

		$query     = 'query( $fail1: Boolean, $fail2: Boolean ) {
			testFailingType {
				try( fail: $fail1 )
				trying( fail: $fail2 )
			}
		}';
		$variables = array( 'fail1' => true );
		$response  = $this->graphql( compact( 'query', 'variables' ) );

		$expected = array(
			$this->expectedErrorPath( 'testFailingType.try' ),
			$this->expectedErrorMessage( 'testErrorQuery worked as expected', self::MESSAGE_EQUALS ),
			$this->expectedErrorMessage( 'worked as', self::MESSAGE_CONTAINS ),
			$this->expectedErrorMessage( 'as expected', self::MESSAGE_ENDS_WITH ),
			$this->expectedErrorMessage( 'testErrorQuery worked', self::MESSAGE_STARTS_WITH ),
			$this->expectedObject( 'testFailingType.try', 'NULL' ),
			$this->expectedObject( 'testFailingType.trying', [ 'No', 'fails', 'here', 'either' ] ),
		);

		// Assert response has error.
		$this->assertQueryError( $response, $expected );

		$variables = array( 'fail2' => true );
		$response  = $this->graphql( compact( 'query', 'variables' ) );

		$expected = array(
			$this->expectedErrorPath( 'testFailingType.trying' ),
			$this->expectedObject( 'testFailingType.try', 'No fails here' ),
			$this->expectedObject( 'testFailingType.trying', 'NULL' ),
		);

		// Assert response has error.
		$this->assertQueryError( $response, $expected );
	}

	public function test_ComplexExpectedNodes() {
		$post_id = $this->factory()->post->create();
		$term_id = $this->factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'category' );

		$query = '
			query {
				posts {
					nodes {
						databaseId
						categories {
							nodes {
								databaseId 
							}
						}
					}
				}
			}
		';

		$response = $this->graphql( compact( 'query' ) );
		$expected = array(
			$this->expectedNode(
				'posts.nodes',
				array(
					$this->expectedObject( 'databaseId', $post_id ),
					$this->expectedNode(
						'categories.nodes',
						array(
							$this->expectedObject( 'databaseId', $term_id )
						),
						0
					)
				)
			)
		);

		$this->assertQuerySuccessful( $response, $expected );
	}

	public function test_ComplexExpectedEdges() {
		$post_id = $this->factory()->post->create();
		$term_id = $this->factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'category' );

		$query = '
			query {
				posts {
					edges {
						node {
							databaseId
							categories {
								edges {
									node {
										databaseId 
									}
								}
							}
						}
					}
				}
			}
		';

		$response = $this->graphql( compact( 'query' ) );
		$expected = array(
			$this->expectedEdge(
				'posts.edges',
				array(
					$this->expectedObject( 'databaseId', $post_id ),
					$this->expectedEdge(
						'categories.edges',
						array(
							$this->expectedObject( 'databaseId', $term_id )
						)
					),
				),
				0
			)
		);

		$this->assertQuerySuccessful( $response, $expected );
	}
}