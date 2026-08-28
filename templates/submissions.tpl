{**
 * templates/submissions.tpl
 *
 * The plugin's own submissions list: every submission at Copyediting stage
 * or later, one row each, with a Send/Generate and Check status action.
 * Reached from the plugin's settings modal, not injected into any OJS
 * workflow page — see ColophonPlugin.php's file doc for why.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<h1 class="app__pageHeading">
	{translate key="plugins.generic.colophon.manage.title"}
</h1>

<div class="app__contentPanel">
	{if empty($colophonRows)}
		<p>{translate key="plugins.generic.colophon.manage.empty"}</p>
	{else}
		<table class="listing" style="width:100%">
			<thead>
				<tr>
					<th>ID</th>
					<th>{translate key="common.title"}</th>
					<th>{translate key="plugins.generic.colophon.manage.stage"}</th>
					<th>{translate key="plugins.generic.colophon.manage.status"}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$colophonRows item=row}
				<tr>
					<td>{$row.id}</td>
					<td>
						<a href="{$row.workflowUrl|escape}" target="_blank" rel="noopener">
							{$row.title|strip_unsafe_html}
						</a>
					</td>
					<td>{$colophonStageLabels[$row.stageId]|default:$row.stageId}</td>
					<td class="colophonStatus" data-submission-id="{$row.id}">
						{if $row.articleCode}{$row.articleCode|escape}{else}—{/if}
					</td>
					<td>
						<span class="colophonRowActions" data-submission-id="{$row.id}"
						      data-has-code="{if $row.articleCode}1{else}0{/if}"
						      data-send-url="{$row.sendUrl|escape}"
						      data-start-url="{$row.startUrl|escape}"
						      data-status-url="{$row.statusUrl|escape}"></span>
					</td>
				</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>
{/block}
