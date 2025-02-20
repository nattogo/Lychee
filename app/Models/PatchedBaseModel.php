<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Class PatchedBaseModel.
 *
 * This model stores all timestamps without timezone information relative to
 * UTC in the database.
 *
 * This class patches the base Model class of Laravel with respect to
 * hydration/de-hydration of Carbon time object from/to database.
 * This patch is inspired by https://github.com/laravel/framework/issues/1841.
 * See https://github.com/laravel/framework/issues/1841#issuecomment-846405008
 * for a summary of discussion and an illustration of the problem.
 *
 * **Attention:**
 * For this class to work properly, the timezone of the database connection
 * must be set to UTC for those RDBM systems (e.g. PosgreSQL, MySQL) which
 * support "timezone aware" database connections.
 * This means the database configuration for MySQL should explicitly include
 * the option `'timezone' => '+00:00'` and the configuration for PostgreSQL
 * should explicitly include the option `'timezone => 'UTC'`.
 * Otherwise those RDBM system interpret a SQL datetime string without an
 * explicit timezone relative to their own default timezone.
 * The default timezone of the database connection might or might or might not
 * be UTC and might or might not be equal to the default timezone of the PHP
 * application.
 * Hence, it is always a good thing to set the timezone of the database
 * connection explicitly.
 * Note, this is not an issue for SQLite which does not support a default
 * timezone for the database connection and always assumes that SQL datetime
 * strings without a timezone are given relative to UTC.
 */
class PatchedBaseModel extends Model
{
	/**
	 * This must match the timezone which is used for the database connection.
	 * For SQLite this poses no problem, because SQLite does not know the
	 * concept of an independent timezone for the database, but it is crucial
	 * for connections to "real" DBMS systems like PostgreSQL and MySQL.
	 * This setting must match the setting from `config/database.php`.
	 * To be more robust, it would be better not to hard-code this timezone,
	 * but to determine the timezone of the database connection
	 * programmatically from the DB connection which is associated to this
	 * model instance.
	 * However, there seems not be an API for that.
	 * TODO: Receive timezone of database connection dynamically at runtime.
	 */
	const DB_TIMEZONE_NAME = 'UTC';

	/**
	 * Converts a DateTime to a storable SQL datetime string.
	 *
	 * This method fixes Model#fromDateTime.
	 * The returned SQL datetime string without a timezone indication always
	 * represents an instant of time relative to
	 * {@link self::DB_TIMEZONE_NAME}.
	 * The original method simply cuts off any timezone information from the
	 * input.
	 *
	 * If the input string has a recognized string format but without a
	 * timezone indication, e.g. something like `YYYY-MM-DD hh:mm:ss`, then
	 * the input string is interpreted as a "wall time" relative to
	 * {@link self::DB_TIMEZONE_NAME}.
	 * As a result, the input string and returned string represent the same
	 * "wall time" without any conversion.
	 * However, the input string and returned string may still differ and
	 * have different string values due to normalization, e.g. the input
	 * string '2020-1-1 8:17' is returned as '2020-01-01 08:17:00'.
	 *
	 * For any input type which has a timezone information (e.g. objects
	 * which inherit \DateTimeInterface, string with explicit timezone
	 * information, etc.) the original timezone is respected and the result
	 * is properly converted to {@link self::DB_TIMEZONE_NAME}.
	 *
	 * @param mixed $value
	 *
	 * @return ?string
	 */
	public function fromDateTime($value): ?string
	{
		// If $value is already an instance of Carbon, the method returns a
		// deep copy, hence it is save to change the timezone below without
		// altering the original object
		$carbonTime = $this->asDateTime($value);
		if (empty($carbonTime)) {
			return null;
		}
		$carbonTime->setTimezone(self::DB_TIMEZONE_NAME);

		return $carbonTime->format($this->getDateFormat());
	}

	/**
	 * Returns a Carbon object.
	 *
	 * This method fixes Model#asDateTime.
	 * For any input without an explicit timezone, the input time is
	 * interpreted relative to {@link self::DB_TIMEZONE_NAME}.
	 * The returned Carbon object uses the application's default timezone
	 * with the date/time properly converted from
	 * {@link self::DB_TIMEZONE_NAME} to `date_default_timezone_get()`.
	 *
	 * In particular, the following holds:
	 *  - If the input value is already a DateTime object (i.e. implements
	 *    \DateTimeInterface), then a new instance of Carbon is returned which
	 *    represents the same date/time and timezone as the input object.
	 *    As the return value is a new instance, it is safe to alter the
	 *    return value without modifying the original object.
	 *  - If the input is an integer, the input is interpreted as seconds
	 *    since epoch (in UTC) and the newly created Carbon object uses the
	 *    application's default timezone.
	 *    In other words, if the input value equals 0 and the application's
	 *    default timezone is `CET`, then the Carbon object will be
	 *    `Carbon\Carbon{ time: '1970-01-01 01:00:00', timezone: 'CET' }`.
	 *  - If the input value is a string _with_ a timezone information, the
	 *    Carbon object will represent that string using the original timezone
	 *    as given by the string.
	 *  - If the input value is a string _without_ a timezone information,
	 *    then the given datetime string is interpreted relative to
	 *    {@link self::DB_TIMEZONE_NAME} and the returned Carbon object uses
	 *    the application's default timezone.
	 *    In other words, if the input value equals '1970-01-01 00:00:00' and
	 *    the application's default timezone is CET, then the Carbon object
	 *    will be
	 *    `Carbon\Carbon{ time: '1970-01-01 01:00:00', timezone: 'CET' }`.
	 *
	 * @param mixed $value
	 *
	 * @return Carbon|null
	 */
	public function asDateTime($value): ?Carbon
	{
		if (empty($value)) {
			return null;
		}

		// If this value is already a Carbon instance, we shall just return it as is.
		// This prevents us having to re-instantiate a Carbon instance when we know
		// it already is one, which wouldn't be fulfilled by the DateTime check.
		if ($value instanceof CarbonInterface) {
			return Date::instance($value);
		}

		// If the value is already a DateTime instance, we will just skip the rest of
		// these checks since they will be a waste of time, and hinder performance
		// when checking the field. We will just return the DateTime right away.
		if ($value instanceof \DateTimeInterface) {
			return Date::parse(
				$value->format('Y-m-d H:i:s.u'), $value->getTimezone()
			);
		}

		// If this value is an integer, we will assume it is a UNIX timestamp's value
		// and format a Carbon object from this timestamp. This allows flexibility
		// when defining your date fields as they might be UNIX timestamps here.
		// Applied patch: Set bare UTC timestamp to the application's default timezone
		if (is_numeric($value)) {
			$result = Date::createFromTimestamp($value);
			$result->setTimezone(date_default_timezone_get());

			return $result;
		}

		// If the value is in simply year, month, day format, we will instantiate the
		// Carbon instances from that format. Again, this provides for simple date
		// fields on the database, while still supporting Carbonized conversion.
		// Applied patch: The standard date format Y-m-d _without_ a timezone
		// is interpreted relative to UTC and _then_ set to the
		// application's default timezone.
		if ($this->isStandardDateFormat($value)) {
			$result = Date::createFromFormat(
				'Y-m-d', $value, self::DB_TIMEZONE_NAME
			)->startOfDay();
			$result->setTimezone(date_default_timezone_get());

			return $result;
		}

		// Finally, we will just assume this date is in the format used by default on
		// the database connection and use that format to create the Carbon object
		// that is returned back out to the developers after we convert it here.
		// Applied patch: Use 'UTC' as the default timezone for string
		// formats which do not include timezone information.
		// Note that the timezone parameter is ignored for formats which
		// include explicit timezone information.
		try {
			$format = $this->getDateFormat();
			$result = Date::createFromFormat(
				$format, $value, self::DB_TIMEZONE_NAME
			);
			if ($result->getTimezone()->getName() === self::DB_TIMEZONE_NAME) {
				// If the timezone is different to UTC, we don't set it, because then
				// the timezone came from the input string.
				// If the timezone equals UTC, then we assume that no explicit timezone
				// information has been given and we set it to the application's
				// default time zone.
				// This is a no-op, if the application's default timezone equals 'UTC'
				// anyway.
				// Note: There is one quirk: If the input string explicitly stated 'UTC'
				// as its timezone, then the time is still set to the app's timezone.
				$result->setTimezone(date_default_timezone_get());
			}

			return $result;
		} catch (InvalidArgumentException $e) {
			// If the specified format did not mach, don't throw an exception,
			// but try to parse the value using an best-effort approach, see below
		}

		// Might throw an InvalidArgumentException if no recognized format is found,
		// but this is intended
		$result = Date::parse($value, self::DB_TIMEZONE_NAME);
		if ($result->getTimezone()->getName() === self::DB_TIMEZONE_NAME) {
			$result->setTimezone(date_default_timezone_get());
		}

		return $result;
	}

	/**
	 * Prepares a date for array/JSON serialization.
	 *
	 * In contrast to the original implementation, this one serializes the
	 * timezone "as is".
	 *
	 * @param \DateTimeInterface $date
	 *
	 * @return string
	 */
	protected function serializeDate(\DateTimeInterface $date): string
	{
		return $date->format(\DateTimeInterface::ISO8601);
	}
}
