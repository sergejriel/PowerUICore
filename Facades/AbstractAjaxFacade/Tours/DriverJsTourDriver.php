<?php
namespace exface\Core\Facades\AbstractAjaxFacade\Tours;

use exface\Core\Interfaces\Facades\HttpFacadeInterface;
use exface\Core\Interfaces\Tours\TourDriverInterface;
use exface\Core\Interfaces\Tours\TourInterface;
use exface\Core\Interfaces\Tours\TourStepInterface;
use exface\Core\Widgets\Filter;

/**
 * This class is a tour driver that uses the driver.js library to create interactive tours on the UI.
 * 
 * The definition of the tour is provided by the Tour.php class 
 * and all the corresponding steps are provided by the TourStep.php class.
 * 
 * @author: Sergej Riel
 */
class DriverJsTourDriver implements TourDriverInterface
{
    private HttpFacadeInterface $facade;
    private array $steps = [];
    
    public function __construct(HttpFacadeInterface $httpFacade)
    {
        $this->facade = $httpFacade;
    }

    /**
     * {@inheritDoc}
     * @see TourDriverInterface::getFacade()
     */
    public function getFacade(): HttpFacadeInterface
    {
        return $this->facade;
    }

    /**
     * {@inheritDoc}
     * @see TourDriverInterface::addStep()
     */
    public function registerStep(TourStepInterface $step) : TourDriverInterface
    {
        $this->steps[] = $step;
        return $this;
    }
    
    //TODO: implement grouping by waypoints.
    /**
     * Gets the steps for the given tour, filtered by the tour's waypoint route and sorted by their order.
     * 
     * The steps are sorted in the following order:
     * 1. Free steps (without any specific position)
     * 2. Absolute steps (with position_in_tour set)
     * 3. Relative steps (with position_after_position_in_tour set)
     * 
     * Absolute steps are inserted based on their position_in_tour value, while relative steps are inserted based on their position_after_position_in_tour value.
     * Steps with the same position value are inserted in the order they appear in the original steps array.
     * 
     * {@inheritDoc}
     * @see TourDriverInterface::getTourSteps()
     */
    public function getTourSteps(TourInterface $tour): array
    {
        // Gets all steps that match the tour's waypoints:
        $matchedSteps = $this->getMatchedStepsWithIndex($tour);

        // Groups steps by their positioning type (absolute, relative, free):
        // absolute: position_in_tour is set
        // relative: position_after_position_in_tour is set
        // free: neither is set
        $groupedSteps = $this->groupStepsByTourPositioning($matchedSteps);
        
        // Start with free steps, then insert absolute and relative steps in the correct order:
        $sorted = $groupedSteps['free'];
        $sorted = $this->insertStepsByAbsolutePosition($sorted, $groupedSteps['absolute']);
        $sorted = $this->insertStepsByRelativePosition($sorted, $groupedSteps['relative']);

        return $this->extractSteps($sorted);
    }

    /**
     * Gets all steps that match the tour's waypoints.
     * 
     * @param TourInterface $tour
     * @return array
     */
    private function getMatchedStepsWithIndex(TourInterface $tour): array
    {
        $matchedSteps = [];
        $tourWaypoints = explode('&', $tour->getWaypointsRoute());
        $takeAllWaypoints = in_array('~all', $tourWaypoints, true);

        foreach ($this->steps as $index => $step) {
            $stepWaypoints = $step->getWaypoints();

            if ($takeAllWaypoints || !empty(array_intersect($tourWaypoints, $stepWaypoints))) {
                $matchedSteps[] = [
                    'step' => $step,
                    'index' => $index,
                ];
            }
        }

        return $matchedSteps;
    }

    /**
     * Groups the steps into three categories based on their positioning in the tour:
     * - absolute: steps with position_in_tour set
     * - relative: steps with position_after_position_in_tour set
     * - free: steps without any specific positioning
     * 
     * @param array $steps
     * @return array
     */
    private function groupStepsByTourPositioning(array $steps): array
    {
        $absolute = [];
        $relative = [];
        $free = [];

        foreach ($steps as $entry) {
            $step = $entry['step'];

            $positionInTour = $step->getPositionInTour();
            $positionAfterPosition = $step->getPositionAfterPositionInTour();

            $hasAbsolutePosition = $positionInTour !== null && $positionInTour !== '';
            $hasRelativePosition = $positionAfterPosition !== null && $positionAfterPosition !== '';

            // position_in_tour comes before position_after_position
            if ($hasAbsolutePosition) {
                $absolute[] = $entry;
                continue;
            }

            if ($hasRelativePosition) {
                $relative[] = $entry;
                continue;
            }

            $free[] = $entry;
        }

        return [
            'absolute' => array_values($absolute),
            'relative' => array_values($relative),
            'free' => array_values($free),
        ];
    }

    /**
     * Inserts steps with absolute positions into the sorted array, based on their position_in_tour value.
     * Steps with the same position_in_tour value are inserted in the order they appear in the original steps array.
     * 
     * Example: Step with position_in_tour = 3 will be inserted as the third step.
     * 
     * @param array $sorted
     * @param array $stepsToInsert
     * @return array
     */
    private function insertStepsByAbsolutePosition(array $sorted, array $stepsToInsert): array
    {
        return $this->insertStepsByPosition(
            $sorted,
            $stepsToInsert,
            [$this, 'compareByAbsolutePosition'],
            static fn($step): int => (int) $step->getPositionInTour(),
            static fn(int $position): int => max(0, $position - 1) //converts position to zero-based index (position 2 -> index 1)
        );
    }

    /**
     * Inserts steps with relative positions into the sorted array, based on their position_after_position_in_tour value.
     * Steps with the same position_after_position_in_tour value are inserted in the order they appear in the original steps array.
     * 
     * Example: Step with position_after_position_in_tour = 3 will be inserted after the step with position_in_tour = 3.
     * 
     * @param array $sorted
     * @param array $stepsToInsert
     * @return array
     */
    private function insertStepsByRelativePosition(array $sorted, array $stepsToInsert): array
    {
        return $this->insertStepsByPosition(
            $sorted,
            $stepsToInsert,
            [$this, 'compareByRelativePosition'],
            static fn($step): int => (int) $step->getPositionAfterPositionInTour(),
            static fn(int $position): int => max(0, $position)
        );
    }

    /**
     * Inserts steps into the sorted array based on their position values, using the provided comparator and position resolvers.
     * Steps with the same position value are inserted in the order they appear in the original steps array.
     * 
     * @param array $sorted
     * @param array $stepsToInsert
     * @param callable $comparator
     * @param callable $positionResolver
     * @param callable $baseIndexResolver
     * @return array
     */
    private function insertStepsByPosition(
        array $sorted,
        array $stepsToInsert,
        callable $comparator,
        callable $positionResolver,
        callable $baseIndexResolver
    ): array {
        usort($stepsToInsert, $comparator);

        $insertedByPosition = [];

        foreach ($stepsToInsert as $entry) {
            $position = $positionResolver($entry['step']);
            $baseInsertIndex = $baseIndexResolver($position);
            $offsetForSamePosition = $insertedByPosition[$position] ?? 0;

            $insertIndex = min(
                count($sorted),
                $baseInsertIndex + $offsetForSamePosition
            );

            array_splice($sorted, $insertIndex, 0, [$entry]);
            $insertedByPosition[$position] = $offsetForSamePosition + 1;
        }

        return array_values($sorted);
    }

    /**
     * Comparator for sorting steps by their absolute position in the tour (position_in_tour).
     * 
     * @param array $a
     * @param array $b
     * @return int
     */
    private function compareByAbsolutePosition(array $a, array $b): int
    {
        return $this->compareByPositionValue($a, $b, static fn($step): int => (int) $step->getPositionInTour());
    }

    /**
     * Comparator for sorting steps by their relative position in the tour (position_after_position_in_tour).
     * 
     * @param array $a
     * @param array $b
     * @return int
     */
    private function compareByRelativePosition(array $a, array $b): int
    {
        return $this->compareByPositionValue($a, $b, static fn($step): int => (int) $step->getPositionAfterPositionInTour());
    }

    /**
     * General comparator for sorting steps by a given position value, using the provided position resolver.
     * Steps with the same position value are sorted by their original index to maintain order.
     * 
     * @param array $a
     * @param array $b
     * @param callable $positionResolver
     * @return int
     */
    private function compareByPositionValue(array $a, array $b, callable $positionResolver): int
    {
        $aPos = $positionResolver($a['step']);
        $bPos = $positionResolver($b['step']);

        if ($aPos === $bPos) {
            return $a['index'] <=> $b['index'];
        }

        return $aPos <=> $bPos;
    }

    /**
     * Extracts the step objects from the given array of entries, which contain both the step and its original index.
     * 
     * @param array $entries
     * @return array
     */
    private function extractSteps(array $entries): array
    {
        return array_map(
            static fn(array $entry) => $entry['step'],
            $entries
        );
    }

    /**
     * @inheritDoc
     */
    public function getWorkbench()
    {
        return $this->facade->getWorkbench();
    }

    /**
     * Builds the JavaScript code to start the tour using driver.js library, 
     * based on the steps of the given tour.
     * 
     * @param TourInterface $tour
     * @return string
     */
    public function buildJsStartTour(TourInterface $tour) : string
    {
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
        $aStepsJs = '';
        
        $steps = $this->getTourSteps($tour);
        if (empty ($steps))  {
            $aStepsJs .= <<<JS
                {
                    popover: {
                      title: {$this->escapeString('This tour is empty')}
                    }
                },
JS;
        } else {
            foreach ($steps as $step) {
                $aStepsJs .= <<<JS
                {
                    element: '#{$this->getStepHighlightedElementId($step)}',
                    popover: {
                      title: {$this->escapeString($step->getTitle())},
                      description: {$this->escapeString($step->getBody())},
                      side: '{$step->getSide()}',
                      align: '{$step->getAlign()}',
                    }
                },
JS;
            }
        }
        $aStepsJs = '[' . $aStepsJs . ']';
        
        $driverJs = <<<JS

            const driverObj = driver.js.driver({
                showProgress: '{$tour->getShowProgress()}',
                disableActiveInteraction: '{$tour->getDisableActiveInteraction()}',
                nextBtnText: '{$translator->translate('TOUR.STEP.ACTION.NEXT')}',
                prevBtnText: '{$translator->translate('TOUR.STEP.ACTION.PREVIOUS')}',
                doneBtnText: '{$translator->translate('TOUR.STEP.ACTION.DONE')}',
                steps: {$aStepsJs}
            });
    
            driverObj.drive();
            
JS;

     return $driverJs;
    }
    
    protected function escapeString($value) : string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * This method returns the ID of the DOM element widget that this step will highlight.
     * 
     * The popover for this step will be displayed next to this element.
     * 
     * @param TourStepInterface $step
     * @return string
     */
    public function getStepHighlightedElementId(TourStepInterface $step): string
    {
        $widget = $step->getWidget();
        
        // Filters will mostly not have any id - they just render their input_widget, so we take that
        if ($widget instanceof Filter) {
            $widget = $widget->getInputWidget();
        }
        
        return $this->getFacade()->getElement($widget)->getId();
    }
}