Flake 
=====

Version 0.002

This is alpha software. DONOT USE IT ON PRODUCTION.

Mixture of Forking and Evented does not always work correctly.

Forking is always safer than Event. Event server crashes on fatal.


## Benchmarks ##
	* Forking - 483 concurrency [ ab stats ]. Beware of thundering herd problem.
	* Event - above 1000 concurrency [ ab stats ]. Solves c10K.

## License ##
	Think GPL2.

# Contributions are welcome. ##
	* Please follow conventions.
	* Handlers were meant for extensions.
	* New classes in flake.php would be a last resort.
