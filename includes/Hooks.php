<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\DiscordNotifications;

use APIBase;
use Config;
use ConfigFactory;
use Exception;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Diff\TextDiffer\ManifoldTextDiffer;
// use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\BlockIpCompleteHook;
// use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\MainConfigNames;
// use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
// use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentityValue;
use RequestContext;
use TextSlotDiffRenderer;
use Title;
use TitleFactory;
use Wikimedia\IPUtils;

class Hooks implements
	// AfterImportPageHook,
	// ArticleProtectCompleteHook,
	BlockIpCompleteHook,
	LocalUserCreatedHook,
	// PageDeleteCompleteHook,
	// PageMoveCompleteHook,
	PageSaveCompleteHook,
	UploadCompleteHook,
	UserGroupsChangedHook
{

	/** @var Config */
	private $config;

	/** @var DiscordNotifier */
	private $discordNotifier;

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/**
	 * @param ConfigFactory $configFactory
	 * @param DiscordNotifier $discordNotifier
	 * @param RevisionLookup $revisionLookup
	 * @param TitleFactory $titleFactory
	 * @param UserFactory $userFactory
	 * @param UserGroupManager $userGroupManager
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		DiscordNotifier $discordNotifier,
		RevisionLookup $revisionLookup,
		TitleFactory $titleFactory,
		UserFactory $userFactory,
		UserGroupManager $userGroupManager,
		WikiPageFactory $wikiPageFactory
	) {
		$this->config = $configFactory->makeConfig( 'DiscordNotifications' );

		$this->discordNotifier = $discordNotifier;
		$this->revisionLookup = $revisionLookup;
		$this->titleFactory = $titleFactory;
		$this->userFactory = $userFactory;
		$this->userGroupManager = $userGroupManager;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		// If null edit, return
		if ( $editResult->isNullEdit() ) {
			return;
		}

		$isNew = (bool)( $flags & EDIT_NEW );

		// If edit article notifs disabled, return
		if ( !$this->config->get( 'DiscordNotificationEditedArticle' ) && !$isNew ) {
			return;
		}

		// If add article notifs disabled, return
		if ( !$this->config->get( 'DiscordNotificationAddedArticle' ) && $isNew ) {
			return;
		}

		// If file upload, return
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == $this->discordNotifier->getMessage( 'discordnotifications-file-namespace' ) ) {
			return;
		}

		$summary = strip_tags( $summary );


		$enableExperimentalCVTFeatures = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
				$this->config->get( 'DiscordExperimentalWebhook' );

		$content = '';
		$shouldSendToCVTFeed = false;
		$experimentalLanguageCode = '';

		if ( $isNew ) {

			$message = $this->discordNotifier->getMessage( 'discordnotifications-article-created',
				$this->discordNotifier->getDiscordUserText( $user ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage ),
				$summary == '' ? '' : $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-summary', $summary )
			);

			if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes', sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_inserted', [], null, $wikiPage->getTitle() );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );

			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $this->config->get( 'DiscordIgnoreMinorEdits' ) ) {
				return;
			}

			$message = $this->discordNotifier->getMessage(
				'discordnotifications-article-saved',
				$this->discordNotifier->getDiscordUserText( $user ),
				$isMinor ? $this->discordNotifier->getMessage( 'discordnotifications-article-saved-minor-edits' ) : $this->discordNotifier->getMessage( 'discordnotifications-article-saved-edit' ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage, true ),
				$summary == '' ? '' : $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-summary', $summary )
			);

			if (
				$this->config->get( 'DiscordIncludeDiffSize' ) &&
				$this->revisionLookup->getPreviousRevision( $revisionRecord )
			) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes',
					sprintf( '%+d', $revisionRecord->getSize() - $this->revisionLookup->getPreviousRevision( $revisionRecord )->getSize() )
				) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_saved', [], null, $wikiPage->getTitle() );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( !$this->config->get( 'DiscordNotificationNewUser' ) ) {
			return;
		}

		if ( !$this->config->get( 'DiscordNotificationIncludeAutocreatedUsers' ) && $autocreated ) {
			return;
		}

		$email = '';
		$realname = '';
		$ipaddress = '';

		try {
			$email = $user->getEmail();
		} catch ( Exception $e ) {
		}

		try {
			$realname = $user->getRealName();
		} catch ( Exception $e ) {
		}

		try {
			$ipaddress = $user->getRequest()->getIP();
		} catch ( Exception $e ) {
		}

		$messageExtra = '';
		if ( $this->config->get( 'DiscordShowNewUserEmail' ) || $this->config->get( 'DiscordShowNewUserFullName' ) || $this->config->get( 'DiscordShowNewUserIP' ) ) {
			$messageExtra = '(';

			if ( $this->config->get( 'DiscordShowNewUserEmail' ) ) {
				$messageExtra .= $email . ', ';
			}

			if ( $this->config->get( 'DiscordShowNewUserFullName' ) ) {
				$messageExtra .= $realname . ', ';
			}

			if ( $this->config->get( 'DiscordShowNewUserIP' ) ) {
				$messageExtra .= $ipaddress . ', ';
			}

			// Remove trailing comma
			$messageExtra = substr( $messageExtra, 0, -2 );
			$messageExtra .= ')';
		}

		$message = $this->discordNotifier->getMessage( 'discordnotifications-new-user',
			$this->discordNotifier->getDiscordUserText( $user ),
			$messageExtra
		);

		$webhook = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
			$this->config->get( 'DiscordExperimentalCVTSendAllNewUsers' ) ?
			$this->config->get( 'DiscordExperimentalWebhook' ) :
			( $this->config->get( 'DiscordExperimentalNewUsersWebhook' ) ?: null );

		if ( !$autocreated ) {
			if ( $webhook && $this->config->get( 'DiscordExperimentalFeedLanguageCode' ) ) {
				$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-new-user', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
					$this->discordNotifier->getDiscordUserText( $user, $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), true ),
					$messageExtra
				);

				if ( $this->config->get( 'DiscordExperimentalCVTUsernameFilter' ) && $this->discordNotifier->isOffensiveUsername( $user->getName() ) ) {
					$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-new-user-filtered', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
						$this->discordNotifier->getDiscordUserText( $user, $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), true ),
						$messageExtra
					);
				}
			}

			$this->discordNotifier->notify( $messageInLanguage ?? $message, $user, 'new_user_account', [], $webhook );
		}

		if ( $webhook || $autocreated ) {
			$this->discordNotifier->notify( $message, $user, 'new_user_account' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onUploadComplete( $uploadBase ) {
		if ( !$this->config->get( 'DiscordNotificationFileUpload' ) ) {
			return;
		}

		$localFile = $uploadBase->getLocalFile();

		$lang = RequestContext::getMain()->getLanguage();
		$user = RequestContext::getMain()->getUser();

		$message = $this->discordNotifier->getMessage( 'discordnotifications-file-uploaded',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $localFile->getTitle()->getFullText() ),
			$localFile->getTitle()->getText(),
			$localFile->getMimeType(),
			$lang->formatSize( $localFile->getSize() ),
			'',
			strip_tags( $localFile->getDescription() )
		);

		$this->discordNotifier->notify( $message, $user, 'file_uploaded' );
	}

	/**
	 * @inheritDoc
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		if ( !$this->config->get( 'DiscordNotificationBlockedUser' ) ) {
			return;
		}

		$reason = $block->getReasonComment()->text;

		$message = $this->discordNotifier->getMessage( 'discordnotifications-block-user',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->getDiscordUserText(
				$block->getTargetUserIdentity() ?? UserIdentityValue::newAnonymous( $block->getTargetName() )
			),
			$reason == '' ? '' : $this->discordNotifier->getMessage( 'discordnotifications-block-user-reason' ) . " '" . $reason . "'.",
			$block->getExpiry() === 'infinity' ? 'infinity' : '<t:' . wfTimestamp( TS_UNIX, $block->getExpiry() ) . ':F>',
			'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . $this->discordNotifier->getMessage( 'discordnotifications-block-user-list' ) . '>.'
		);

		$webhook = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
			$this->config->get( 'DiscordExperimentalCVTSendAllUserBlocks' ) ?
			$this->config->get( 'DiscordExperimentalWebhook' ) :
			( $this->config->get( 'DiscordExperimentalUserBlocksWebhook' ) ?: null );

		if ( $webhook ) {
			$experimentalLanguageCode = $this->config->get( 'DiscordExperimentalFeedLanguageCode' );
			if ( $experimentalLanguageCode ) {
				$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user',
					$experimentalLanguageCode,
					$this->discordNotifier->getDiscordUserText( $user, $experimentalLanguageCode ),
					$this->discordNotifier->getDiscordUserText(
						$block->getTargetUserIdentity() ?? UserIdentityValue::newAnonymous( $block->getTargetName() ),
						$experimentalLanguageCode, true
					),
					$reason == '' ? '' : $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user-reason', $experimentalLanguageCode ) . " '" . $reason . "'.",
					$block->getExpiry() === 'infinity' ? 'infinity' : '<t:' . wfTimestamp( TS_UNIX, $block->getExpiry() ) . ':F>',
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user-list', $experimentalLanguageCode ) . '>.'
				);
			}

			$this->discordNotifier->notify( $messageInLanguage ?? $message, $user, 'user_blocked', [], $webhook );
		}

		$this->discordNotifier->notify( $message, $user, 'user_blocked' );
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ) {
		if ( !$this->config->get( 'DiscordNotificationUserGroupsChanged' ) ) {
			return;
		}

		if ( $user->getWikiId() !== WikiAwareEntity::LOCAL ) {
			// TODO: Support external users
			return;
		}

		$message = $this->discordNotifier->getMessage( 'discordnotifications-change-user-groups-with-old',
			$this->discordNotifier->getDiscordUserText( $performer ),
			$this->discordNotifier->getDiscordUserText( $user ),
			implode( ', ', array_keys( $oldUGMs ) ),
			implode( ', ', $this->userGroupManager->getUserGroups( $user ) ),
			'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserRights' ) . $this->discordNotifier->getDiscordUserText( $performer ) ) . '|' . $this->discordNotifier->getMessage( 'discordnotifications-view-user-rights' ) . '>.'
		);

		$this->discordNotifier->notify( $message, $user, 'user_groups_changed' );
	}

	/**
	 * @param APIBase $module
	 */
	public function onAPIFlowAfterExecute( APIBase $module ) {
		if ( !$this->config->get( 'DiscordNotificationFlow' ) || !ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			return;
		}

		$request = RequestContext::getMain()->getRequest();

		$action = $module->getModuleName();
		$request = $request->getValues();
		$result = $module->getResult()->getResultData()['flow'][$action];

		if ( $result['status'] != 'ok' ) {
			return;
		}

		$title = Title::newFromText( $request['page'] );
		$user = RequestContext::getMain()->getUser();

		switch ( $action ) {
			case 'edit-header':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-header',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'edit-post':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-post',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-title':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-title',
					$this->discordNotifier->getDiscordUserText( $user ),
					$request['etcontent'],
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-topic-summary':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-topic-summary',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'lock-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					$this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic-' . $request['cotmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-post':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-post',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					$this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-' . $request['mpmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					$this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-' . $request['mtmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'new-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-new-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['committed']['topiclist']['topic-id'] ) . '|' . $request['nttopic'] . '>',
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'reply':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-reply',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			default:
				return;
		}

		$this->discordNotifier->notify( $message, $user, 'flow', [], null, $title );
	}
}
