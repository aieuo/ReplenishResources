<?php

namespace aieuo\replenish\mineflow\action;

use aieuo\mineflow\flowItem\base\PositionFlowItem;
use aieuo\mineflow\flowItem\base\PositionFlowItemTrait;
use aieuo\mineflow\flowItem\FlowItem;
use aieuo\mineflow\flowItem\FlowItemCategory;
use aieuo\mineflow\flowItem\FlowItemExecutor;
use aieuo\mineflow\formAPI\element\mineflow\PositionVariableDropdown;
use aieuo\mineflow\utils\Language;
use aieuo\replenish\ReplenishResourcesAPI;


class ReplenishResource extends FlowItem implements PositionFlowItem {
    use PositionFlowItemTrait;

    protected string $id = "replenishResource";

    protected string $name = "action.replenishResource.name";
    protected string $detail = "action.replenishResource.detail";
    protected array $detailDefaultReplace = ["position"];

    protected string $category = FlowItemCategory::PLUGIN;

    public function __construct(string $position = "") {
        $this->setPositionVariableName($position);
    }

    public function isDataValid(): bool {
        return $this->getPositionVariableName() !== "";
    }

    public function getDetail(): string {
        if (!$this->isDataValid()) return $this->getName();
        return Language::get($this->detail, [$this->getPositionVariableName()]);
    }

    public function execute(FlowItemExecutor $source): \Generator {
        $this->throwIfCannotExecute();

        $position = $this->getPosition($source);

        $api = ReplenishResourcesAPI::getInstance();
        $api->replenish($position);
        yield true;
    }

    public function getEditFormElements(array $variables): array {
        return [
            new PositionVariableDropdown($variables, $this->getPositionVariableName()),
        ];
    }

    public function loadSaveData(array $content): FlowItem {
        $this->setPositionVariableName($content[0]);
        return $this;
    }

    public function serializeContents(): array {
        return [$this->getPositionVariableName()];
    }
}