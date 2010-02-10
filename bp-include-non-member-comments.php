<?php
/*
Plugin Name: BP Include Non-member Comments
Plugin URI: http://teleogistic.net/bp-include-non-member-comments
Description: Inserts blog comments from non-logged-in users into the activity stream 
Version: 1.0
Author: Boone Gorges
Author URI: http://teleogistic.net
Site Wide Only: true
*/

/* Comment out the following two lines to run in BP 1.1.3 */
// add_action( 'comment_post', 'bp_blogs_record_nonmember_comment_old', 8, 2 );
// add_filter( 'bp_activity_content_filter', 'bp_nonmember_comment_content', 10, 4 );

/* Checks to see if BP is loaded */
function bp_blogs_nonmember_comment_loader() {
	add_action( 'comment_post', 'bp_blogs_record_nonmember_comment', 8, 2 );
	add_filter( 'bp_activity_content_filter', 'bp_nonmember_comment_content', 10, 4 );
}

if ( defined( 'BP_VERSION' ) )
	bp_blogs_nonmember_comment_loader();
else
	add_action( 'bp_init', 'bp_blogs_nonmember_comment_loader', 8, 2 );
/* end BP check */

function bp_blogs_record_nonmember_comment( $comment_id, $is_approved ) {
	global $wpdb, $bp;

	if ( !$is_approved )
		return false;

	$comment = get_comment($comment_id);
	$comment->post = get_post( $comment->comment_post_ID );
	
	/* Get the user_id from the author email. */
	$user = get_user_by_email( $comment->comment_author_email );
	$user_id = (int)$user->ID;

	if ( $user_id )
		return false;

	/* If this is a password protected post, don't record the comment */
	if ( !empty( $post->post_password ) )
		return false;
		
	/* If we're on a multiblog install, record this post */
	if ( function_exists('bp_core_is_multisite') && bp_core_is_multisite() || !function_exists('bp_core_is_multisite') ) {
	
		$recorded_comment = new BP_Blogs_Comment;
		$recorded_comment->user_id = false;
		$recorded_comment->blog_id = $wpdb->blogid;
		$recorded_comment->comment_id = $comment_id;
		$recorded_comment->comment_post_id = $comment->comment_post_ID;
		$recorded_comment->date_created = strtotime( $comment->comment_date_gmt );

		$recorded_commment_id = $recorded_comment->save();

		bp_blogs_update_blogmeta( $recorded_comment->blog_id, 'last_activity', gmdate( "Y-m-d H:i:s" ) );
	}

	if ( (int)get_blog_option( $recorded_comment->blog_id, 'blog_public' ) || !bp_core_is_multisite() ) {
		/* Record in activity streams */
		$comment_link = bp_post_get_permalink( $comment->post, $wpdb->blogid ) . '#comment-' . $comment_id;
		$activity_action = sprintf( __( '%s commented on the blog post %s', 'buddypress' ), '<a href="' . $comment->comment_author_url . '">' . $comment->comment_author . '</a>', '<a href="' . $comment_link . '#comment-' . $comment->comment_ID . '">' . $comment->post->post_title . '</a>' );
		$activity_content = $comment->comment_content;

		/* Record this in activity streams */
		bp_blogs_record_activity( array(
			'user_id' => false,
			'action' => apply_filters( 'bp_blogs_activity_new_comment_action', $activity_action, &$comment, &$recorded_comment, $comment_link ),
			'content' => apply_filters( 'bp_blogs_activity_new_comment_content', $activity_content, &$comment, &$recorded_comment, $comment_link ),
			'primary_link' => apply_filters( 'bp_blogs_activity_new_comment_primary_link', $comment_link, &$comment, &$recorded_comment ),
			'type' => 'new_blog_comment',
			'item_id' => $wpdb->blogid,
			'secondary_item_id' => $comment_id,
			'recorded_time' => $comment->comment_date_gmt
		) );
	}

	return $recorded_comment;
}

/* For BP < 1.2 */
function bp_blogs_record_nonmember_comment_old( $comment_id, $is_approved ) {
	global $wpdb, $bp;

	if ( !$is_approved )
		return false;

	$comment = get_comment($comment_id);
	$comment->post = get_post( $comment->comment_post_ID );

	/* Get the user_id from the author email. */
	$user = get_user_by_email( $comment->comment_author_email );
	$user_id = (int)$user->ID;

	if ( $user_id )
		return false;

	/* If this is a password protected post, don't record the comment */
	if ( !empty( $post->post_password ) )
		return false;

	$recorded_comment = new BP_Blogs_Comment;
	$recorded_comment->user_id = $user_id;
	$recorded_comment->blog_id = $wpdb->blogid;
	$recorded_comment->comment_id = $comment_id;
	$recorded_comment->comment_post_id = $comment->comment_post_ID;
	$recorded_comment->date_created = strtotime( $comment->comment_date );

	$recorded_commment_id = $recorded_comment->save();

	bp_blogs_update_blogmeta( $recorded_comment->blog_id, 'last_activity', time() );

	if ( (int)get_blog_option( $recorded_comment->blog_id, 'blog_public' ) ) {
		/* Record in activity streams */
		$comment_link = bp_post_get_permalink( $comment->post, $recorded_comment->blog_id );
		$activity_content = sprintf( __( '%s commented on the blog post %s', 'buddypress' ), '<a href="' . $comment->comment_author_url . '">' . $comment->comment_author . '</a>', '<a href="' . $comment_link . '#comment-' . $comment->comment_ID . '">' . $comment->post->post_title . '</a>' );
		$activity_content .= '<blockquote>' . bp_create_excerpt( $comment->comment_content ) . '</blockquote>';

		/* Record this in activity streams */
		bp_blogs_record_activity( array(
			'user_id' => $recorded_comment->user_id,
			'content' => apply_filters( 'bp_blogs_activity_new_comment', $activity_content, &$comment, &$recorded_comment, $comment_link ),
			'primary_link' => apply_filters( 'bp_blogs_activity_new_comment_primary_link', $comment_link, &$comment, &$recorded_comment ),
			'component_action' => 'new_blog_comment',
			'item_id' => $comment_id,
			'secondary_item_id' => $recorded_comment->blog_id,
			'recorded_time' =>  $recorded_comment->date_created
		) );
	}

	return $recorded_comment;
}



function bp_nonmember_comment_content( $content ) {
	global $bp;
	
	if ( $bp->loggedin_user->id != 0 )
		return $content;
	
	/* Todo: Add patch to core that makes these buttons not appear for user_id=0 */
	$content = preg_replace( "|(View</a>).*?<a href=.+?>Delete</a></span>|", '$1</span>', $content ); // for bp-default 1.2+
	$content = preg_replace( "|<span class=\"activity-delete-link.+?</span>|", '', $content ); // for bp-classic
	
	return $content;
}
?>