<?php

use Prowebcraft\Telebot\AnswerInline;

class CoachBot extends \Prowebcraft\Telebot\Telebot
{

    const DECISION_YES = 'yes';
    const DECISION_NO = 'no';
    const DECISION_MAYBE = 'maybe';

    /**
     * Начать перекличку
     * @admin
     */
    public function callCommand()
    {
        $reason = $this->getParams($this->e);
        $reply = $this->getRosterHeader($reason);
        $buttons = $this->getCallButtons();
        $post = $this->askInline($reply, $buttons, 'onCallReply');
        $this->setChatConfig('session', [
            'id' => $post->getMessageId(),
            'status' => 'open',
            'reason' => $reason,
            'starter' => $this->getUserId(),
            'users' => [],
            'time' => time()
        ]);
    }

    public function onCallReply(AnswerInline $answer)
    {
        if (!($this->getChatConfig('session.status') == 'open')) {
            $answer->reply("Перекличка завершена 💩", true);
            return;
        }
        $userId = $this->getUserId();
        $decision = $answer->getData();
        $this->setChatConfig("session.users.{$userId}", $decision);
        $this->addChatConfig("session.log", [
            'time' => date("Y-m-d H:i:s"),
            'user' => $this->getFromName(null, true),
            'answer' => $decision
        ]);
        $this->updateRosterMessage();
        $answer->reply("👌 Голос учтен", false);
    }

    public function updateRosterMessage()
    {
        if (!($this->getChatConfig('session.status') == 'open')) {
            $this->reply('Перекличка завершена');
            return;
        }
        $reply = $this->getRosterHeader($this->getChatConfig('session.reason'));
        $reply .= "\n---------------------------------------------------\n";
        $reply .= "<b>Результаты переклички</b>: \n ";
        $decisions = [
            self::DECISION_YES => [],
            self::DECISION_NO => [],
            self::DECISION_MAYBE => []
        ];
        foreach ($this->getChatConfig('session.users', []) as $userId => $decision) {
            $decisions[$decision][] = $userId;
        }
        foreach ([
            self::DECISION_YES => "👍  Будут",
            self::DECISION_NO => "😔 Не будут",
            self::DECISION_MAYBE => "🤷‍♂️ Может быть",
         ] as $decision => $label) {
            $count = count($decisions[$decision]);
            if ($count) {
                $reply .= "<b>{$label}</b> ($count)\n";
                foreach ($decisions[$decision] as $user) {
                    $reply .= "  ☇ " . $this->getUserName($user, $userId);
                }
            }

        }
        $rosterId = $this->getChatConfig('session.id');
        $buttons = $this->getCallButtons();
        $this->updateInlineMessage($rosterId, $reply, $buttons);
    }

    /**
     * @param $reason
     * @return string
     */
    protected function getRosterHeader($reason)
    {
        $reply = "⚽️ <b>Внимание! Начинаем перекличку!</b> ⚽\n";
        if (!empty($reason)) $reply .= "---------------------------------------------------\n📆 <i>$reason</i>\n";
        return $reply;
    }

    /**
     * @return array
     */
    protected function getCallButtons()
    {
        $buttons = [];
        $buttons[] = [
            [
                'text' => "👍  Буду",
                'callback_data' => self::DECISION_YES
            ],
            [
                'text' => "😔 Не буду",
                'callback_data' => self::DECISION_NO
            ],
            [
                'text' => "🤷‍♂️ Может быть",
                'callback_data' => self::DECISION_MAYBE
            ],
        ];
        return $buttons;
    }

}
