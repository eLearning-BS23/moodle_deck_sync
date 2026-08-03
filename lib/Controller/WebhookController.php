<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Controller;

use OCA\MoodleDeckSync\Db\SyncEventMapper;
use OCA\MoodleDeckSync\Service\BoardProvisioner;
use OCA\MoodleDeckSync\Service\SyncResult;
use OCA\MoodleDeckSync\Service\WebhookEvent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class WebhookController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SyncEventMapper $syncEventMapper,
		private readonly BoardProvisioner $boardProvisioner,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function receive(): DataResponse {
		/** @var mixed $content */
		$content = $this->request->getParams();
		if (empty($content)) {
			$body = file_get_contents('php://input');
			if (is_string($body) && $body !== '') {
				try {
					$content = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				} catch (\JsonException) {
					return new DataResponse([
						'ocs' => [
							'meta' => ['status' => 'error', 'statuscode' => 400, 'message' => 'Malformed JSON'],
							'data' => [],
						],
					], 400);
				}
			}
		}

		if (!is_array($content)) {
			return new DataResponse([
				'ocs' => [
					'meta' => ['status' => 'error', 'statuscode' => 400, 'message' => 'Invalid body format'],
					'data' => [],
				],
			], 400);
		}

		try {
			$event = WebhookEvent::fromArray($content);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([
				'ocs' => [
					'meta' => ['status' => 'error', 'statuscode' => 400, 'message' => $e->getMessage()],
					'data' => [],
				],
			], 400);
		}

		try {
			$claim = $this->syncEventMapper->claim($event->instance(), $event->eventId(), $event->eventType());
		} catch (\RuntimeException) {
			return new DataResponse([
				'ocs' => [
					'meta' => ['status' => 'ok', 'statuscode' => 409, 'message' => 'Duplicate event'],
					'data' => [],
				],
			], 409);
		}

		try {
			$result = match ($event->eventType()) {
				'course_module_created', 'group_created' => $this->boardProvisioner->provision($event),
				'group_member_added', 'group_member_removed' => $this->boardProvisioner->reconcileMember($event),
				'group_deleted' => $this->boardProvisioner->archive($event),
				default => SyncResult::failed('unsupported_event_type'),
			};

			$this->syncEventMapper->markResult(
				$claim->getId(),
				$result->result(),
				$result->safeError() ?? ''
			);

			$statusCode = $result->result() === 'failed' ? 500 : 200;

			return new DataResponse([
				'ocs' => [
					'meta' => ['status' => 'ok', 'statuscode' => $statusCode, 'message' => 'OK'],
					'data' => [
						'board_id' => $result->boardId(),
						'board_url' => $result->boardUrl(),
					],
				],
			], $statusCode);
		} catch (\Throwable $e) {
			$this->syncEventMapper->markResult($claim->getId(), 'failed', $e->getMessage());
			return new DataResponse([
				'ocs' => [
					'meta' => ['status' => 'error', 'statuscode' => 500, 'message' => 'Internal server error'],
					'data' => [],
				],
			], 500);
		}
	}
}
