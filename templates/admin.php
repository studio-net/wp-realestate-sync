<div class="wrap">

	<div class="gedeonLogo"></div>


	<h2><?=__("Options", "wpres")?></h2>

	<form action="options.php" method="POST">

		<?php settings_fields('wp-re-sync'); ?>

		<?php do_settings_sections('wp-re-sync'); ?>

		<?php submit_button(__("Save Changes", "wpres")); ?>

	</form>




	<h2><?=__("Actions", "wpres")?></h2>

	<?php if ($syncIsRunning): ?>

		<p>
			<img src="<?=$imgUrl?>/spinner.gif" alt=""/>
			<?=__("The properties are currently syncing with Gedeon.", "wpres")?>
		</p>

		<p><?=__("Press this button to refresh this page.", "wpres")?></p>

		<a href="<?=admin_url('options-general.php?page=wp-re-sync')?>"
			class="button button-secondary"><?=__("Refresh", "wpres")?></a>

		<p><?=__("You will be able to look at the log details once the syncing is done.", "wpres")?></p>
	
	<?php else: ?>

		<p><?=__("Press this button to synchronize the properties from gedon to your site, now.", "wpres")?></p>

		<a href="<?=admin_url('options-general.php?page=wp-re-sync')?>&launch-sync-bg" class="button button-primary"><?=__("Sync Now !", "wpres")?></a>

	<?php endif ?>


	<h2><?=__("Sync Logs", "wpres")?></h2>

	<?php if (empty($logHistory)): ?>

		<p><?=__("No sync history.", "wpres")?></p>

	<?php else: ?>
	
		<ul class="logHistory">

			<?php foreach (array_reverse($logHistory) as $date): ?>

				<?php $ts = strtotime($date) ?>

				<li>
				<?= date("d/m/Y H:i", $ts) ?> - 

				<a href="<?=admin_url('options-general.php?page=wp-re-sync')?>&details=<?=urlencode($date)?>">Détails</a>

				</li>

			<?php endforeach ?>

		</ul>

	<?php endif ?>


	<?php if ($details): ?>

		<div class="logDetails">

			<h3><?=sprintf(__("Sync Logs on %s", "wpres"), $logDate)?> :</h3>

			<pre>
			<?=join("", $details)?>
			</pre>

		</div>

	<?php endif ?>


</div>
