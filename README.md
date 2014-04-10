Flake v0.001

This is alpha software. DONOT USE IT ON PRODUCTION.

Mixture of Forking and Event personalities donot always yield appropiate results. Do it only if you have appropiate knowledge.
To be on the safe side go with Forking personality. Event based server will crash on fatal. Keep this in mind while coding.   


Notes for contribution.
	-	Kindly follow coding conventions. I don't care if you hate them.
	-	Try to put any additions in handlers.
	-	If not create a new class in flake.php as a last resort.
	

Benchmarks
	Forking - 483 concurrency [ ab stats ]. Beware of thundering herd problem.
	Event 	- above 1000 concurrency [ ab stats ]. Solves c10K.


License
	Think GPL2. (animesh.kundu@payu.in)
