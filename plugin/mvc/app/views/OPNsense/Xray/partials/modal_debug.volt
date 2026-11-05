<!-- Debug Info Modal -->
<div class="modal fade" id="debugInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-clipboard"></i> {{ xlang._('Debug Info') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ xlang._('Select all (Ctrl+A / Cmd+A) and copy (Ctrl+C / Cmd+C), then paste into your issue report.') }}
                </p>
                <textarea id="debugInfoContent" readonly cols="1000"
                          rows="20"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ xlang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>