# twilio-nest

Control your nest thermostat via SMS using Twilio

## Background

I originaly wrote a stand alone PHP library for controlling the
Nest thermostat that would cache session information to reduce
the load on the Nest servers.  Now that there are several
PHP Nest APIs out there, I figured I would include the Twilio
code as well, even though its not nearly as polished as the 
API.


## Dependencies

* PHP Server
* [HTTP_Request2](http://pear.php.net/package/HTTP_Request2)
* [twilio-php](https://github.com/twilio/twilio-php) (Install using PEAR)

## Setup

Clone this repo in to a folder that is web accessible.  

Open the sms.php file to configure your Twilio key and to
set which phone numbers can control your Nest.

The config folder shouldn't be accessible over the web, and it's
location can be set when you instantiate the Nest object in
sms.php.  Anyone with access to the contents of that folder
will be able to control your entire Nest account.

If you get an error message back in a text message it means
that something went wrong, likely in communication with the 
Nest server.  Trying again likely won't help and further
debugging is required to get things working again.  This
usually happens when either Nest changed something, or
something is mis-configured in your client.

Sometime deleting the config/nest.credentials file fixes errors
by forcing the library to get a new session token.

## Miscellaneous

The twilio portion of this code was originaly thrown together
to let someone else without a smart phone control the thermostat
in my house.  

Even though the twilio portion isn't full featured, the PHP 
library allows you to look at everything using the `status`
member variable.  To update it, call the `fetchStatus()` method.

The twilio portion only lets you set the temperature between 58 and 
82 to avoid typos.
