<?php

namespace w\Bot;

use Slack\Message\Attachment;
use Slack\Message\MessageBuilder;
use w\Bot\structures\Action;
use w\Bot\structures\ActionAttachment;

class FootballState
{
    protected $playersNeeded;

    /**
     * @var null|\SQLite3
     */
    public $db = null;

    /**
     * FootballState constructor.
     * @param int $playersNeeded
     */
    public function __construct($playersNeeded = null)
    {
        $this->db = new Database();
        if ($playersNeeded == 2 || $playersNeeded == 4) {
            $this->playersNeeded = $playersNeeded;
        } else {
            $this->playersNeeded = 4;
        }
        return $this;
    }


    /**
     * Return true if joined, false if already present
     * @param string $player
     * @return bool
     */
    public function join($player): bool
    {
        if (in_array($player, $this->getJoinedPlayers(), true)) {
            return false;
        }

        if (!$this->db->getActiveGame()) {
            return $this->db->createActiveGame($player);
        }

        return $this->db->addPlayer($player);
    }

    /**
     * Return true if removed player
     * @param string $player
     * @return bool
     */
    public function leave($player): bool
    {
        if (!in_array($player, $this->getJoinedPlayers())) {
            return false;
        }

        return $this->db->removePlayer($player);
    }

    /**
     * @return int
     */
    public function getPlayerCount(): int
    {
        return count($this->getJoinedPlayers());
    }

    /**
     * @param string $player
     * @return bool
     */
    public function isManager($player): bool
    {
        if ($player === getenv('ADMIN')) {
            return true;
        }

        if ($this->getPlayerCount() == 0) {
            return false;
        }

        if ($this->amI($player)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $player
     * @return bool
     */
    public function isFirst($player): bool
    {
        $players = $this->getJoinedPlayers();

        if (empty($players)) {
            return false;
        }

        if ($players[0] == $player) {
            return true;
        }

        return false;
    }

    /**
     * @param $player
     * @return bool
     */
    public function start($player): bool
    {
        if ($this->getPlayerCount() != $this->playersNeeded) {
            return false;
        }

        $playId = $this->db->createGameInstances($player, $this->getJoinedPlayers());
        if ($playId === -1) {
            return false;
        }

        $this->db->startGame($playId);

        return true;
    }

    /**
     * @return array
     */
    public function getJoinedPlayers(): array
    {
        return $this->db->getPlayers();
    }

    /**
     * @return int
     */
    public function getPlayersNeeded(): int
    {
        return $this->playersNeeded;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $this->db->clearActiveGame();

        return true;
    }

    /**
     * @param MessageBuilder $messageBuilder
     * @return MessageBuilder
     */
    public function getMessage(MessageBuilder $messageBuilder)
    {
        if ($this->db->getStatus() == 'started') {
            return $this->getPostGameState($messageBuilder);
        }

        return $this->getGameState($messageBuilder);
    }

    /**
     * @param string $player
     * @return bool
     */
    public function amI($player): bool
    {
        return in_array($player, $this->getJoinedPlayers(), true);
    }

    /**
     * @param MessageBuilder $messageBuilder
     * @return MessageBuilder
     */
    protected function getGameState(MessageBuilder $messageBuilder)
    {
        $status = $this->db->getStatus();
        if ($status == 'none') {
            $messageBuilder->setText('Cancelled!');
            return $messageBuilder;
        }

        $text = sprintf("Join to play a game!\n"
            . "Status: *%s*\n"
            . "Players joined (%d/%d): %s",
            $status,
            count($this->getJoinedPlayers()),
            $this->getPlayersNeeded(),
            implode(' ', array_map(function ($userId) {
                return '<@' . $userId . '>';
            }, $this->getJoinedPlayers()))
        );

        $messageBuilder->setText($text);

        $attachment = new ActionAttachment('Do you want to play a game?', 'Choose one of actions',
            'You are unable to join', '#3AA3E3');
        $attachment->setCallbackId('game');

        if ($this->getPlayerCount() != $this->getPlayersNeeded()) {
            $action = new Action("game", "Join", "button", "join", "primary");
            $attachment->addAction($action);
        }
        if ($status != 'started') {
            $action = new Action("game", "Leave", "button", "leave", "danger");
            $attachment->addAction($action);
        }
        $action = new Action("game", "Cancel", "button", "cancel"); // Add confirm
        $attachment->addAction($action);

        if ($this->getPlayerCount() == $this->getPlayersNeeded() && $status != 'started') {
            $action = new Action("game", "Start", "button", "start");
            $attachment->addAction($action);
        }
        $action = new Action("game", "Refresh", "button", "refresh");
        $attachment->addAction($action);
        $action = new Action("game", "Notify", "button", "notify");
        $attachment->addAction($action);
        $messageBuilder->addAttachment($attachment);

        return $messageBuilder;
    }

    /**
     * @param MessageBuilder $messageBuilder
     * @return null|MessageBuilder
     */
    protected function getPostGameState(MessageBuilder $messageBuilder)
    {
        $gameInstances = $this->db->getActiveGameInstances();

        if (empty($gameInstances)) {
            return null;
        }

        $messageBuilder->setText('Update game results!');

        foreach ($gameInstances as $k => $instance) {
            if ($instance['status'] == 'deleted') {
                $attachment = new Attachment('Game #' . ($k + 1), 'Done!');
            } else {
                if (is_null($instance['player_3']) && is_null($instance['player_4'])) {
                    $attachment = new ActionAttachment(
                        'Game #' . ($k + 1),
                        sprintf("Team A: <@%s>\nTeam B: <@%s>",
                            $instance['player_1'],
                            $instance['player_2']
                        ),
                        'You are unable to join',
                        '#3AA3E3'
                    );
                } else {
                    $attachment = new ActionAttachment(
                        'Game #' . ($k + 1),
                        sprintf("Team A: <@%s> <@%s>\nTeam B: <@%s> <@%s>",
                            $instance['player_1'],
                            $instance['player_2'],
                            $instance['player_3'],
                            $instance['player_4']),
                        'You are unable to join',
                        '#3AA3E3'
                    );
                }

                $attachment->setCallbackId('update');
                $action = new Action('update_' . $instance['id'], 'A', 'button', 'A', 'primary');
                $attachment->addAction($action);
                $action = new Action('update_' . $instance['id'], 'B', 'button', 'B', 'danger');
                $attachment->addAction($action);
                $action = new Action('update_' . $instance['id'], 'Did not play', 'button', '-');
                $attachment->addAction($action);
            }
            $messageBuilder->addAttachment($attachment);
        }
        $attachment = new ActionAttachment('Actions', '', 'You are unable to join', '#AA3AE3');
        $attachment->setCallbackId('game');
        $action = new Action("game", "Refresh", "button", "refresh");
        $attachment->addAction($action);
        $action = new Action("game", "Cancel", "button", "cancel");
        $attachment->addAction($action);
        $messageBuilder->addAttachment($attachment);

        return $messageBuilder;
    }

    /**
     * @return bool
     */
    public function isFinishedGame()
    {
        $gameInstances = $this->db->getActiveGameInstances();
        $deletedGameInstances = array_filter($gameInstances, function ($instance) {
            return $instance['status'] == 'deleted';
        });
        if (count($gameInstances) == count($deletedGameInstances)) {
            return true;
        }

        return false;
    }

    /**
     * @param array $rankByWonGames
     * @return array
     */
    public function getRankByWinRate(array $rankByWonGames = [])
    {
        usort($rankByWonGames, function ($userA, $userB) {
            $scoreA = $userA['games_played'] ? $userA['games_won'] / $userA['games_played'] : 0;
            $scoreB = $userB['games_played'] ? $userB['games_won'] / $userB['games_played'] : 0;

            if ($scoreA > $scoreB) {
                return -1;
            } else if ($scoreA < $scoreB) {
                return 1;
            } else {
                if ($userA['games_won'] > $userB['games_won']) {
                    return -1;
                } else if ($userA['games_won'] < $userB['games_won']) {
                    return 1;
                }
            }

            return 0;
        });

        return $rankByWonGames;
    }

    /**
     * @param array $rankByWonGames
     * @param array $rankByWinRate
     * @return array
     */
    public function mergeRanks(array $rankByWonGames, array $rankByWinRate)
    {
        $userIdDictionary = [];
        foreach ($rankByWinRate as $index => $user) {
            $userIdDictionary[$user['id']] = [
                'user' => $user,
                'rank' => $index,
            ];
        }

        foreach ($rankByWonGames as $index => $user) {
            $userIdDictionary[$user['id']]['rank'] += $index;
            $userIdDictionary[$user['id']]['rank'] /= 2.0;
        }

        usort($userIdDictionary, function ($a, $b) {
            return $a['rank'] > $b['rank'];
        });

        $rank = [];

        foreach ($userIdDictionary as $userId => $rankData) {
            $rank[] = $rankData['user'];
        }

        return $rank;
    }
}