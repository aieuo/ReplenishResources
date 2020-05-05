<?php

namespace aieuo\formAPI;

use pocketmine\utils\TextFormat;
use pocketmine\form\Form as PMForm;
use pocketmine\Player;

abstract class Form implements PMForm {

    const MODAL_FORM = "modal";
    const LIST_FORM = "form";
    const CUSTOM_FORM = "custom_form";

    /** @var string */
    protected $type;
    /** @var string */
    protected $title = "";

    /** @var string */
    private $name;

    /** @var callable|null */
    private $onReceive = null;
    /* @var callable|null */
    private $onClose = null;
    /** @var array */
    private $args = [];
    /** @var array */
    protected $messages = [];
    /** @var array */
    protected $highlights = [];

    public function __construct(string $title = "") {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self {
        $this->title = $title;
        return $this;
    }

    /**
     * @return string
     */
    public function getTitle(): string {
        return $this->title;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name ?? $this->getTitle();
    }

    /**
     * @param callable $callable
     * @return self
     */
    public function onReceive(callable $callable): self {
        $this->onReceive = $callable;
        return $this;
    }

    /**
     * @param callable $callable
     * @return self
     */
    public function onClose(callable $callable): self {
        $this->onClose = $callable;
        return $this;
    }

    /**
     * @param mixed ...$args
     * @return self
     */
    public function addArgs(...$args): self {
        $this->args = array_merge($this->args, $args);
        return $this;
    }

    /**
     * @param string $error
     * @param integer $index
     * @return self
     */
    public function addError(string $error, int $index): self {
        $this->messages[TextFormat::RED.$error.TextFormat::WHITE] = true;
        if ($index !== null) $this->highlights[$index] = TextFormat::YELLOW;
        return $this;
    }

    /**
     * @param array $errors
     * @return self
     */
    public function addErrors(array $errors): self {
        foreach ($errors as $error) {
            $this->addError($error[0], $error[1]);
        }
        return $this;
    }

    /**
     * @param string $message
     * @return self
     */
    public function addMessage(string $message): self {
        $this->messages[$message] = true;
        return $this;
    }

    /**
     * @param string[] $messages
     * @return self
     */
    public function addMessages(array $messages): self {
        foreach ($messages as $message) {
            $this->addMessage($message);
        }
        return $this;
    }

    /**
     * @param Player $player
     * @return self
     */
    public function show(Player $player): self {
        $player->sendForm($this);
        return $this;
    }

    /**
     * @return array
     */
    abstract public function jsonSerialize(): array;

    /**
     * @param array $form
     * @return array
     */
    abstract public function reflectErrors(array $form): array;

    public function handleResponse(Player $player, $data): void {
        if ($data === null) {
            if (!is_callable($this->onClose)) return;
            call_user_func_array($this->onClose, array_merge([$player], $this->args));
        } else {
            if (!is_callable($this->onReceive)) return;
            call_user_func_array($this->onReceive, array_merge([$player, $data], $this->args));
        }
    }
}