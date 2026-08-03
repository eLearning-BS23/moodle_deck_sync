<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Deck;

interface DeckClient {
	/**
	 * @return array{id:int,title:string,color:string}
	 */
	public function createBoard(string $title, string $color): array;

	/**
	 * @return array{id:int,title:string,order:int}
	 */
	public function createStack(int $boardId, string $title, int $order): array;

	/**
	 * @return list<array{id:int,title:string,order:int}>
	 */
	public function listStacks(int $boardId): array;

	/**
	 * @return list<array{
	 *     id:int,
	 *     participant:string,
	 *     permissionEdit:bool,
	 *     permissionShare:bool,
	 *     permissionManage:bool
	 * }>
	 */
	public function listAcl(int $boardId): array;

	/**
	 * @param array{permissionEdit:bool,permissionShare:bool,permissionManage:bool} $permissions
	 */
	public function addAcl(int $boardId, string $uid, array $permissions): int;

	/**
	 * @param array{permissionEdit:bool,permissionShare:bool,permissionManage:bool} $permissions
	 */
	public function updateAcl(int $boardId, int $aclId, array $permissions): void;

	public function removeAcl(int $boardId, int $aclId): void;

	public function archiveBoard(int $boardId): void;

	public function deleteBoard(int $boardId): void;
}
