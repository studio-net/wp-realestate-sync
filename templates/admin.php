<div class="wrap">

	<div class="gedeonLogo"></div>


	<h2><?=__("Options", "wpgedeon")?></h2>

	<form action="options.php" method="POST">

		<?php settings_fields('gedeon-sync'); ?>

		<?php do_settings_sections('gedeon-sync'); ?>

		<?php submit_button(__("Save Changes", "wpgedeon")); ?>

	</form>




	<h2><?=__("Actions", "wpgedeon")?></h2>

	<?php if ($syncIsRunning): ?>

		<p>
			<img src="http://icons.lsi.im/spinners/041.gif" alt=""/>
			<?=__("The properties are currently syncing with Gedeon.", "wpgedeon")?>
		</p>

		<p><?=__("Press this button to refresh this page.", "wpgedeon")?></p>

		<a href="<?=admin_url('options-general.php?page=gedeon-sync')?>"
			class="button button-secondary"><?=__("Refresh", "wpgedeon")?></a>

		<p><?=__("You will be able to look at the log details once the syncing is done.", "wpgedeon")?></p>
	
	<?php else: ?>

		<p><?=__("Press this button to synchronize the properties from gedon to wpcasa, now.", "wpgedeon")?></p>

		<p><?=__("This syncing is done every hours, automatically.", "wpgedeon")?></p>

		<a href="<?=admin_url('options-general.php?page=gedeon-sync')?>&launch-sync-bg" class="button button-primary"><?=__("Sync Now !", "wpgedeon")?></a>

	<?php endif ?>


	<h2><?=__("Sync Logs", "wpgedeon")?></h2>

	<?php if (empty($logHistory)): ?>

		<p><?=__("No sync history.", "wpgedeon")?></p>

	<?php else: ?>
	
		<ul class="logHistory">

			<?php foreach (array_reverse($logHistory) as $date): ?>

				<?php $ts = strtotime($date) ?>

				<li>
				<?= date("d/m/Y H:i", $ts) ?> - 

				<a href="<?=admin_url('options-general.php?page=gedeon-sync')?>&details=<?=urlencode($date)?>">Détails</a>

				</li>

			<?php endforeach ?>

		</ul>

	<?php endif ?>


	<?php if ($details): ?>

		<div class="logDetails">

			<h3><?=sprintf(__("Sync Logs on %s", "wpgedeon"), $logDate)?> :</h3>

			<pre>
			<?=join("", $details)?>
			</pre>

		</div>

	<?php endif ?>


</div>
