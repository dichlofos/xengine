XEngine Project
===============

XEngine was extracted as a separate project from LESh project.
Contains "standard" PHP library, auxillary functions, drop-in replacements
for unsafe PHP methods.

Used in several projects:

* https://bitbucket.org/dichlofos/financial-equalizer
* https://github.com/lesh-dev/core

and will be used in many others.

Logo and History
================

1. An *X* means *anything*. This library contains everything you need.
2. All XEngine code born during crutch-based development. That's why two crossed crutches is our logo.

Please note we really don't like crutches. This library helps you not to produce crutches in buisness logic.

Development
===========
Every project using trunk XEngine must be aware of XEngine changes
and typically should not be broken.

Major refactorings and API changes are not allowed, or allowed with updates
of all using projects. If you need to change API drastically, consider
adding add new methods/classes with different signature.

Components
==========
* String unicode-aware utilities (convenient wrappers around mbstring)
* Database engine
* Mailer subsystem
* Notification engine

Contrib components
==================
* bcrypt library
* PHPMailer library

Authors and Contributors
========================
Alexander Trousevitch <trousev@yandex.ru>

* Initial XCMS engine design
* Initial Contest design
* Deploy system
* Project administration
* Hosting support, RedMine administration

Mikhail Veltishchev <dichlofos-mv@yandex.ru>, https://github/dichlofos

* Project administration and management
* Major XEngine enhancements and maintenance
* Database engine
* Mailer and notifications