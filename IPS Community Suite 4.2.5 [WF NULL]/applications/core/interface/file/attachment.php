<?php
/**
 * @brief		Attachment Download Handler
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		30 May 2013
 */

require_once str_replace( 'applications/core/interface/file/attachment.php', '', str_replace( '\\', '/', __FILE__ ) ) . 'init.php';
\IPS\Session\Front::i();

try
{
	/* Load member */
	$member = \IPS\Member::loggedIn();
	
	/* Init */
	$permission = FALSE;
	$loadedExtensions = array();
	
	/* Get attachment */
	$attachment = \IPS\Db::i()->select( '*', 'core_attachments', array( 'attach_id=?', \IPS\Request::i()->id ) )->first();

	if( $member->member_id )
	{
		if ( $member->member_id == $attachment['attach_member_id'] )
		{
			$permission	= TRUE;
		}
	}

	if( $permission !== TRUE )
	{
		foreach ( \IPS\Db::i()->select( '*', 'core_attachments_map', array( 'attachment_id=?', $attachment['attach_id'] ) ) as $map )
		{
			if ( !isset( $loadedExtensions[ $map['location_key'] ] ) )
			{
				$exploded = explode( '_', $map['location_key'] );
				try
				{
					$extensions = \IPS\Application::load( $exploded[0] )->extensions( 'core', 'EditorLocations' );
					if ( isset( $extensions[ $exploded[1] ] ) )
					{
						$loadedExtensions[ $map['location_key'] ] = $extensions[ $exploded[1] ];
					}
				}
				catch ( \OutOfRangeException $e ) { }
			}
					
			if ( isset( $loadedExtensions[ $map['location_key'] ] ) )
			{
				try
				{
					if ( $loadedExtensions[ $map['location_key'] ]->attachmentPermissionCheck( $member, $map['id1'], $map['id2'], $map['id3'], $attachment ) )
					{
						$permission = TRUE;
						break;
					}
				}
				catch ( \OutOfRangeException $e ) { }
			}
		}
	}
		
	/* Permission check */
	if ( !$permission )
	{
		\IPS\Dispatcher\External::i();
		\IPS\Output::i()->error( 'no_module_permission', '2C171/1', 403, '' );
	}

	/* Get file and data */
	$file		= \IPS\File::get( 'core_Attachment', $attachment['attach_location'] );
	$headers	= array_merge( \IPS\Output::getCacheHeaders( time(), 360 ), array( "Content-Disposition" => \IPS\Output::getContentDisposition( 'attachment', $attachment['attach_file'] ), "X-Content-Type-Options" => "nosniff" ) );

	/* Update download counter */
	\IPS\Db::i()->update( 'core_attachments', "attach_hits=attach_hits+1", array( 'attach_id=?', $attachment['attach_id'] ) );
	
	/* If it's an AWS file just redirect to it */
	if ( $file instanceof \IPS\File\Amazon )
	{
		\IPS\Output::i()->redirect( $file->generateTemporaryDownloadUrl() );
	}
	
	/* Send headers and print file */
	\IPS\Output::i()->sendStatusCodeHeader( 200 );
	\IPS\Output::i()->sendHeader( "Content-type: " . \IPS\File::getMimeType( $file->originalFilename ) . ";charset=UTF-8" );

	foreach( $headers as $key => $header )
	{
		\IPS\Output::i()->sendHeader( $key . ': ' . $header );
	}
	\IPS\Output::i()->sendHeader( "Content-Length: " . $file->filesize() );

	$file->printFile();
	exit;
}
catch ( \UnderflowException $e )
{
	/* Remove previously sent headers, so that the browser doesn't try to download this error as a file */
	header_remove();
	\IPS\Dispatcher\External::i();
	\IPS\Output::i()->error( 'node_error', '2S328/1', 404, '' );
}
catch ( \ErrorException $e )
{
	/* Remove previously sent headers, so that the browser doesn't try to download this error as a file */
	header_remove();
	\IPS\Dispatcher\External::i();
	\IPS\Output::i()->error( 'node_error', '2C327/1', 404, '' );
}