<?php

namespace aieuo\replenish\mineflow\action;

use aieuo\mineflow\flowItem\base\PositionFlowItem;
use aieuo\mineflow\flowItem\base\PositionFlowItemTrait;
use aieuo\mineflow\flowItem\FlowItem;
use aieuo\mineflow\formAPI\CustomForm;
use aieuo\mineflow\formAPI\element\mineflow\CancelToggle;
use aieuo\mineflow\formAPI\element\Label;
use aieuo\mineflow\formAPI\element\mineflow\PositionVariableDropdown;
use aieuo\mineflow\formAPI\Form;
use aieuo\mineflow\recipe\Recipe;
use aieuo\mineflow\utils\Category;
use aieuo\mineflow\utils\Language;
use aieuo\replenish\ReplenishResourcesAPI;


class ReplenishResource extends FlowItem implements PositionFlowItem {
    use PositionFlowItemTrait;

    protected $id = "replenishResource";

    protected $name = "action.replenishResource.name";
    protected $detail = "action.replenishResource.detail";
    protected $detailDefaultReplace = ["position"];

    protected $category = Category::PLUGIN;

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

    public function execute(Recipe $origin) {
        $this->throwIfCannotExecute();

        $position = $this->getPosition($origin);
        $this->throwIfInvalidPosition($position);

        $api = ReplenishResourcesAPI::getInstance();
        $api->replenish($position);
        yield true;
    }

    public function getEditForm(array $variables = []): Form {
        return (new CustomForm($this->getName()))
            ->setContents([
                new Label($this->getDescription()),
                new PositionVariableDropdown($variables, $this->getPositionVariableName()),
                new CancelToggle()
            ]);
    }

    public function parseFromFormData(array $data): array {
        return ["contents" => [$data[1]], "cancel" => $data[2]];
    }

    public function loadSaveData(array $content): FlowItem {
        $this->setPositionVariableName($content[0]);
        return $this;
    }

    public function serializeContents(): array {
        return [$this->getPositionVariableName()];
    }
}