<?php
namespace FluidTYPO3\Vhs\ViewHelpers\Iterator;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Claus Due <claus@namelesscoder.net>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use FluidTYPO3\Vhs\Utility\ViewHelperUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyObjectStorage;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Fluid\Core\ViewHelper\Exception;

/**
 * Sorts an instance of ObjectStorage, an Iterator implementation,
 * an Array or a QueryResult (including Lazy counterparts).
 *
 * Can be used inline, i.e.:
 * <f:for each="{dataset -> vhs:iterator.sort(sortBy: 'name')}" as="item">
 *    // iterating data which is ONLY sorted while rendering this particular loop
 * </f:for>
 *
 * @author Claus Due <claus@namelesscoder.net>
 * @package Vhs
 * @subpackage ViewHelpers\Iterator
 */
class SortViewHelper extends AbstractViewHelper {

	/**
	 * Contains all flags that are allowed to be used
	 * with the sorting functions
	 *
	 * @var array
	 */
	protected $allowedSortFlags = array(
		'SORT_REGULAR',
		'SORT_STRING',
		'SORT_NUMERIC',
		'SORT_NATURAL',
		'SORT_LOCALE_STRING',
		'SORT_FLAG_CASE'
	);

	/**
	 * Initialize arguments
	 *
	 * @return void
	 */
	public function initializeArguments() {
		$this->registerArgument('as', 'string', 'Which variable to update in the TemplateVariableContainer. If left out, returns sorted data instead of updating the variable (i.e. reference or copy)');
		$this->registerArgument('sortBy', 'string', 'Which property/field to sort by - leave out for numeric sorting based on indexes(keys)');
		$this->registerArgument('order', 'string', 'ASC, DESC, RAND or SHUFFLE. RAND preserves keys, SHUFFLE does not - but SHUFFLE is faster', FALSE, 'ASC');
		$this->registerArgument('sortFlags', 'string', 'Constant name from PHP for SORT_FLAGS: SORT_REGULAR, SORT_STRING, SORT_NUMERIC, SORT_NATURAL, SORT_LOCALE_STRING or SORT_FLAG_CASE. You can provide a comma seperated list or array to use a combination of flags.', FALSE, 'SORT_REGULAR');
	}

	/**
	 * "Render" method - sorts a target list-type target. Either $array or
	 * $objectStorage must be specified. If both are, ObjectStorage takes precedence.
	 *
	 * Returns the same type as $subject. Ignores NULL values which would be
	 * OK to use in an f:for (empty loop as result)
	 *
	 * @param array|\Iterator $subject An array or Iterator implementation to sort
	 * @throws \Exception
	 * @return mixed
	 */
	public function render($subject = NULL) {
		$as = $this->arguments['as'];
		if (NULL === $subject && NULL === $as) {
			// this case enables inline usage if the "as" argument
			// is not provided. If "as" is provided, the tag content
			// (which is where inline arguments are taken from) is
			// expected to contain the rendering rather than the variable.
			$subject = $this->renderChildren();
		}
		$sorted = NULL;
		if (TRUE === is_array($subject)) {
			$sorted = $this->sortArray($subject);
		} else {
			if (TRUE === $subject instanceof ObjectStorage || TRUE === $subject instanceof LazyObjectStorage) {
				$sorted = $this->sortObjectStorage($subject);
			} elseif (TRUE === $subject instanceof \Iterator) {
				/** @var \Iterator $subject */
				$array = iterator_to_array($subject, TRUE);
				$sorted = $this->sortArray($array);
			} elseif (TRUE === $subject instanceof QueryResultInterface) {
				/** @var QueryResultInterface $subject */
				$sorted = $this->sortArray($subject->toArray());
			} elseif (NULL !== $subject) {
				// a NULL value is respected and ignored, but any
				// unrecognized value other than this is considered a
				// fatal error.
				throw new \Exception('Unsortable variable type passed to Iterator/SortViewHelper. Expected any of Array, QueryResult, ' .
					' ObjectStorage or Iterator implementation but got ' . gettype($subject), 1351958941);
			}
		}
		if (NULL !== $as) {
			$variables = array($as => $sorted);
			$content = ViewHelperUtility::renderChildrenWithVariables($this, $this->templateVariableContainer, $variables);
			return $content;
		}
		return $sorted;
	}

	/**
	 * Sort an array
	 *
	 * @param array $array
	 * @return array
	 */
	protected function sortArray($array) {
		$sorted = array();
		foreach ($array as $index => $object) {
			if (TRUE === isset($this->arguments['sortBy'])) {
				$index = $this->getSortValue($object);
			}
			while (isset($sorted[$index])) {
				$index .= '.1';
			}
			$sorted[$index] = $object;
		}
		if ('ASC' === $this->arguments['order']) {
			ksort($sorted, $this->getSortFlags());
		} elseif ('RAND' === $this->arguments['order']) {
			$sortedKeys = array_keys($sorted);
			shuffle($sortedKeys);
			$backup = $sorted;
			$sorted = array();
			foreach ($sortedKeys as $sortedKey) {
				$sorted[$sortedKey] = $backup[$sortedKey];
			}
		} elseif ('SHUFFLE' === $this->arguments['order']) {
			shuffle($sorted);
		} else {
			krsort($sorted, $this->getSortFlags());
		}
		return $sorted;
	}

	/**
	 * Sort a Tx_Extbase_Persistence_ObjectStorage instance
	 *
	 * @param ObjectStorage $storage
	 * @return ObjectStorage
	 */
	protected function sortObjectStorage($storage) {
		/** @var ObjectManager $objectManager */
		$objectManager = GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager');
		/** @var ObjectStorage $temp */
		$temp = $objectManager->get('TYPO3\CMS\Extbase\Persistence\ObjectStorage');
		foreach ($storage as $item) {
			$temp->attach($item);
		}
		$sorted = array();
		foreach ($storage as $index => $item) {
			if (TRUE === isset($this->arguments['sortBy'])) {
				$index = $this->getSortValue($item);
			}
			while (isset($sorted[$index])) {
				$index .= '.1';
			}
			$sorted[$index] = $item;
		}
		if ('ASC' === $this->arguments['order']) {
			ksort($sorted, $this->getSortFlags());
		} elseif ('RAND' === $this->arguments['order']) {
			$sortedKeys = array_keys($sorted);
			shuffle($sortedKeys);
			$backup = $sorted;
			$sorted = array();
			foreach ($sortedKeys as $sortedKey) {
				$sorted[$sortedKey] = $backup[$sortedKey];
			}
		} elseif ('SHUFFLE' === $this->arguments['order']) {
			shuffle($sorted);
		} else {
			krsort($sorted, $this->getSortFlags());
		}
		$storage = $objectManager->get('TYPO3\CMS\Extbase\Persistence\ObjectStorage');
		foreach ($sorted as $item) {
			$storage->attach($item);
		}
		return $storage;
	}

	/**
	 * Gets the value to use as sorting value from $object
	 *
	 * @param mixed $object
	 * @return mixed
	 */
	protected function getSortValue($object) {
		$field = $this->arguments['sortBy'];
		$value = ObjectAccess::getPropertyPath($object, $field);
		if (TRUE === $value instanceof \DateTime) {
			$value = intval($value->format('U'));
		} elseif (TRUE === $value instanceof ObjectStorage || TRUE === $value instanceof LazyObjectStorage) {
			$value = $value->count();
		} elseif (is_array($value)) {
			$value = count($value);
		}
		return $value;
	}

	/**
	 * Parses the supplied flags into the proper value for the sorting
	 * function.
	 *
	 * @return integer
	 */
	protected function getSortFlags() {
		$constants = ViewHelperUtility::arrayFromArrayOrTraversableOrCSV($this->arguments['sortFlags']);
		$flags = 0;
		foreach ($constants as $constant) {
			if (FALSE === in_array($constant, $this->allowedSortFlags)) {
				throw new Exception('The constant "' . $constant . '" you\'re trying to use as a sortFlag is not allowed. Allowed constants are: ' . implode(', ', $this->allowedSortFlags) . '.', 1404220538);
			}
			$flags = $flags | constant(trim($constant));
		}
		return $flags;
	}

}
