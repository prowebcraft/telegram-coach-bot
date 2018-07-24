<?php

use Prowebcraft\Telebot\Answer;
use Prowebcraft\Telebot\AnswerInline;

class CoachBot extends \Prowebcraft\Telebot\Telebot
{

    const DECISION_YES = 'yes';
    const DECISION_NO = 'no';
    const DECISION_MAYBE = 'maybe';

    public function startCommand()
    {
        if ($this->isChatGroup()) {
            $this->whoCommand();
        } else {
            $this->sendPhoto('AgADAgADxKgxG2LCMEinbDJ0CLbbq5IMMw4ABG3m4guYcA37YBQEAAEC', 'Добавь меня в группу и я вас быстро всех построю! 👊');
        }
    }

    /**
     * Открыть свободный режим
     * @admin
     */
    public function openCommand()
    {
        $this->setChatConfig('mode', 'open');
        $this->reply('Свободный режим активирован. Все могут начать перекличку');
    }

    /**
     * Закрыть свободный режим
     * @admin
     */
    public function closeCommand()
    {
        $this->setChatConfig('mode', 'close');
        $this->reply('Свободный режим отключен. Перекличку могут начать только администраторы');
    }

    /**
     * Начать перекличку, если после команды указать повод, он будет добавлен отдельной строкой
     */
    public function whoCommand()
    {
        if (!$this->isChatGroup()) {
            $this->reply('Прокопенко, ты своей квадратной головой совсем думать разучился? Перекличка только в группе возможна 🙈');
            return;
        }
        if ($this->getChatConfig('mode') != 'open' && !$this->isAdmin()) {
            $this->sendPhoto('AgADAgAD7agxG8jmMUjOFaxkpfygEIQHnA4ABGtOwd_TB95lK2cBAAEC',
                "А сегодня в завтрашний день не все могут смотреть. Вернее смотреть могут не только лишь все, мало кто может это делать ☝️\n"
                . "А уж переклички проводить, так подавно 😎"
            );
            return;
        }
        $reason = $this->getParams($this->e);
        $reply = $this->getRosterHeader($reason);
        $buttons = $this->getCallButtons();
        $post = $this->askInline($reply, $buttons, 'onCallReply');
        $this->setChatConfig('sessions.' . $post->getMessageId(), [
            'status' => 'open',
            'reason' => $reason,
            'starter' => $this->getUserId(),
            'users' => [],
            'time' => time()
        ]);
    }

    /**
     * @param AnswerInline $answer
     */
    public function onCallReply(AnswerInline $answer)
    {
        $sessionId = $answer->getCallbackQuery()->getMessage()->getMessageId();
        if (!($this->getSessionConfig($sessionId, 'status') == 'open')) {
            $answer->reply("Перекличка завершена 💩", true);
            return;
        }
        $userId = $this->getUserId();

        //Mark user as active user (for later pokes)
        $members = $this->getChatConfig('members', []);
        $members[] = $userId;
        $members = array_unique($members);
        $this->setChatConfig('members', $members, false);

        $decision = $answer->getData();
        $this->addSessionConfig($sessionId, 'log', [
            'time' => date("Y-m-d H:i:s"),
            'user' => $this->getFromName(null, true),
            'answer' => $decision
        ]);
        switch ($decision) {
            case self::DECISION_YES:
            case self::DECISION_MAYBE:
            case self::DECISION_NO:
                $this->setSessionConfig($sessionId, "users.{$userId}", $decision);
                $answer->reply("👌 Голос учтен", false);
                break;
            case 'change_title':
                if ($this->canManage($sessionId)) {
                    $answer->reply('Изменить повестку может только организатор или админ 👮');
                    return;
                }
                $ask = "<b>Меняем повестку для переклички</b>";
                if ($reason = $this->getSessionConfig($sessionId, 'reason')) {
                    $ask .= sprintf("\n\n<b>Текущая повестка</b>: %s", $reason);
                }
                $this->ask($ask, null, 'setTitleCallback', false, true, [
                    'id' => $sessionId
                ]);
                break;
            case 'poke':
                if ($this->canManage($sessionId)) {
                    $answer->reply('Это может только организатор или админ 👮');
                    return;
                }
                $members = $this->getChatConfig('members', []);
                $active = array_keys($this->getSessionConfig($sessionId, 'users', []));
                $left = array_diff($members, $active);
                $mention = [];
                foreach ($left as $leftUserId) {
                    $mention[] = sprintf('<a href="tg://user?id=%s">%s</a>', $leftUserId, $this->getUserName($leftUserId));
                }
                if (!empty($mention)) {
                    $message = implode(', ', $mention) .' - просьба отметиться в перекличке';
                    $target = $this->getTarget();
                    if ($target) {
                        try {
                            $this->sendMessage($this->getChatId(), $message, 'HTML', true, $sessionId);
                        } catch (\TelegramBot\Api\Exception $e) {
                            $this->error('Error sending reply: %s', $e->getMessage());
                        }
                    }
                } else {
                    $answer->reply('Нет активных участников для опроса');
                }
                break;
            case 'finish':
                if ($this->canManage($sessionId)) {
                    $answer->reply('Завершить перекличку может только организатор или админ 👮');
                    return;
                }
                $this->setSessionConfig($sessionId, 'status', 'closed');
                break;
        }

        $this->updateRosterMessage($sessionId);
    }
    
    /**
     * @param Answer $answer
     */
    public function setTitleCallback(Answer $answer)
    {
        $id = $answer->getExtraData('id');
        if ($id) {
            $this->setSessionConfig($id, 'reason', $answer->getReplyText());
            $this->updateRosterMessage($id);
            $this->reply('Повестка обновлена - ' . $answer->getReplyText());
        } else {
            $this->error('Error updating title for session. Message Id: %s, Info: %s', $answer->getAskMessageId(), $answer->getInfo());
        }
    }

    /**
     * @param int $id
     * @param null|string $key
     * @param null|mixed $default
     * @return mixed
     */
    public function getSessionConfig($id, $key = null, $default = null)
    {
        if (!($session = $this->getChatConfig("sessions.$id")))
            return false;
        return \Prowebcraft\Dot::getValue($session, $key, $default);
    }

    /**
     * @param $id
     * @param $key
     * @param $value
     * @return $this
     */
    public function setSessionConfig($id, $key, $value)
    {
        $this->setChatConfig("sessions.$id.$key", $value);
        return $this;
    }

    /**
     * @param $id
     * @param $key
     * @param $value
     * @return $this
     */
    public function addSessionConfig($id, $key, $value)
    {
        $this->addChatConfig("sessions.$id.$key", $value);
        return $this;
    }

    /**
     * Обновить список подписки
     */
    public function updateRosterMessage($id)
    {
        if (!$this->getSessionConfig($id)) {
            $this->reply('Отсутствует информация о перекличке');
            return;
        }

        $reply = $this->getRosterHeader($this->getSessionConfig($id, 'reason'));
        $reply .= "------------------------------------------------\n";
        $reply .= "<b>Результаты переклички</b>: \n ";
        $decisions = [
            self::DECISION_YES => [],
            self::DECISION_MAYBE => [],
            self::DECISION_NO => [],
        ];
        foreach ($this->getSessionConfig($id, 'users', []) as $userId => $decision) {
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
                    $reply .= "  ☇ " . $this->getUserName($user, $userId)."\n";
                }
            }

        }
        if (($this->getSessionConfig($id, 'status') == 'open')) {
            $buttons = $this->getCallButtons();
        } else {
            $reply .= "------------------------------------------------\n";
            $reply .= "Перекличка завершена 🏁";
            $buttons = [];
        }

        try {
            $this->updateInlineMessage($id, $reply, $buttons);
        } catch (Exception $e) {
            $this->error("Error updating roster message - %s\nTrace: %s", $e->getMessage(), $e->getTraceAsString());
        }

    }

    /**
     * @param $reason
     * @return string
     */
    protected function getRosterHeader($reason)
    {
        $reply = "⚽️ <b>Внимание! Перекличка!</b>\n";
        if (!empty($reason)) $reply .= "------------------------------------------------\n📆 <i>$reason</i>\n";
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
        $buttons[] = [
            [
                'text' => "📋 Изменить повестку",
                'callback_data' => 'change_title'
            ],
            [
                'text' => "📣 Опросить оставшихся",
                'callback_data' => 'poke'
            ],
            [
                'text' => "🏁 Завершить перекличку",
                'callback_data' => 'finish'
            ]
        ];
        return $buttons;
    }

    /**
     * @param $sessionId
     * @return bool
     */
    protected function canManage($sessionId)
    {
        return $this->getSessionConfig($sessionId, 'starter') != $this->getUserId() && !$this->isAdmin();
    }

}
