<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Image API driver.
 *
 * $Id: Image.php 2008 2008-02-09 06:42:48Z PugFish $
 *
 * @package    Image
 * @author     Kohana Team
 * @copyright  (c) 2007-2008 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */
abstract class Image_Driver {

	// Reference to the current image
	protected $image;

	// Reference to the temporary processing image
	protected $tmp_image;

	// Processing errors
	protected $errors = array();

	/**
	 * Executes a set of actions, defined in pairs.
	 *
	 * @param   array    actions
	 * @return  boolean
	 */
	public function execute($actions)
	{
		foreach($actions as $func => $args)
		{
			if ( ! $this->$func($args))
				return FALSE;
		}

		return TRUE;
	}

	/**
	 * Sanitize and normalize a geometry array based on the temporary image
	 * width and height. Valid properties are: width, height, top, left.
	 *
	 * @param   array  geometry properties
	 * @return  void
	 */
	protected function sanitize_geometry( & $geometry)
	{
		list($width, $height) = $this->properties();

		// Turn off error reporting
		$reporting = error_reporting(0);

		// Width and height cannot exceed current image size
		$geometry['width']  = min($geometry['width'], $width);
		$geometry['height'] = min($geometry['height'], $height);

		switch($geometry['top'])
		{
			case 'center':
				$geometry['top'] = floor(($height / 2) - ($geometry['height'] / 2));
			break;
			case 'top':
				$geometry['top'] = 0;
			break;
			case 'bottom':
				$geometry['top'] = $height - $geometry['height'];
			break;
		}

		switch($geometry['left'])
		{
			case 'center':
				$geometry['left'] = floor(($width / 2) - ($geometry['width'] / 2));
			break;
			case 'left':
				$geometry['left'] = 0;
			break;
			case 'right':
				$geometry['left'] = $width - $geometry['height'];
			break;
		}

		// Restore error reporting
		error_reporting($reporting);
	}

	/**
	 * Return the current width and height of the temporary image. This is mainly
	 * needed for sanitizing the geometry.
	 *
	 * @return  array  width, height
	 */
	abstract protected function properties();

	/**
	 * Process an image with a set of actions.
	 *
	 * @param   string   image filename
	 * @param   array    actions to execute
	 * @param   string   destination directory path
	 * @param   string   destination filename
	 * @return  boolean
	 */
	abstract public function process($image, $actions, $dir, $file);

	/**
	 * Flip an image. Valid directions are horizontal and vertical.
	 *
	 * @param   integer   direction to flip
	 * @return  boolean
	 */
	abstract function flip($direction);

	/**
	 * Crop an image. Valid properties are: width, height, top, left.
	 *
	 * @param   array     new properties
	 * @return  boolean
	 */
	abstract function crop($properties);

	/**
	 * Resize an image. Valid properties are: width, height, and master.
	 *
	 * @param   array     new properties
	 * @return  boolean
	 */
	abstract public function resize($properties);

	/**
	 * Rotate an image. Valid amounts are -180 to 180.
	 *
	 * @param   integer   amount to rotate
	 * @return  boolean
	 */
	abstract public function rotate($amount);

	/**
	 * Sharpen and image. Valid amounts are 1 to 100.
	 *
	 * @param   integer  amount to sharpen
	 * @return  boolean
	 */
	abstract public function sharpen($amount);

} // End Image Driver